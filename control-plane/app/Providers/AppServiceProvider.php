<?php

// SPDX-License-Identifier: Apache-2.0 AND MIT
// Derived from the Laravel application skeleton (laravel/laravel, MIT).

namespace App\Providers;

use App\Application\Events\Audited;
use App\Application\Listeners\RecordAuditEvent;
use App\Application\Services\AuditContext;
use App\Application\Services\LoginAddressHasher;
use App\Domain\Audit\AuditEventRecord;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Policies\AuditEventPolicy;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\Policies\BrandingPolicy;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\Policies\AdministratorPolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use App\Domain\Provisioning\Jitter;
use App\Domain\Provisioning\RetryPolicy;
use App\Http\Middleware\UseSessionRealm;
use App\Infrastructure\Audit\DatabaseAuditLog;
use App\Infrastructure\OpenApi\IncompleteOpenApiDocument;
use App\Infrastructure\Provisioning\RandomJitter;
use App\Infrastructure\TwoFactor\Google2faTotp;
use App\Support\Realm;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The control plane serves no UI. Scramble only generates
        // contracts/api/openapi.json (`composer openapi`).
        Scramble::ignoreDefaultRoutes();

        // Laravel merges its default `web` guard into config/auth.php. The
        // only session guards are the two realms (spec section 28).
        $guards = config('auth.guards');

        if (is_array($guards)) {
            unset($guards['web']);
            config(['auth.guards' => $guards]);
        }

        $this->app->bind(Totp::class, Google2faTotp::class);

        // Decision 0013: callers depend on the interface. Not a singleton,
        // so a test can swap it between two requests.
        $this->app->bind(AuditLog::class, DatabaseAuditLog::class);

        // One per request: the middleware fills it, the listener reads it.
        $this->app->scoped(AuditContext::class);

        $this->app->singleton(
            LoginAddressHasher::class,
            fn (Application $app): LoginAddressHasher => new LoginAddressHasher($app->make(Encrypter::class)->getKey()),
        );

        // Spec section 8: every number is a configurable default. Built on
        // each use, so a wrong setting fails where the policy is needed.
        $this->app->bind(RetryPolicy::class, function (Application $app): RetryPolicy {
            $config = $app->make(Repository::class);

            return new RetryPolicy(
                $config->integer('hostmesh.jobs.retry.base_delay'),
                $config->integer('hostmesh.jobs.retry.max_delay'),
                $config->integer('hostmesh.jobs.retry.max_attempts'),
                $config->float('hostmesh.jobs.retry.jitter'),
                $config->integer('hostmesh.jobs.recheck_delay'),
            );
        });

        $this->app->bind(Jitter::class, RandomJitter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Decision 0012: one policy per resource, registered here. Policies
        // are the only place where authorization is decided.
        Gate::policy(Administrator::class, AdministratorPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(AuditEventRecord::class, AuditEventPolicy::class);
        Gate::policy(BrandingRecord::class, BrandingPolicy::class);

        // Decision 0013: every event that implements Audited is written to
        // the audit log, synchronously, inside the caller's transaction.
        Event::listen(Audited::class, RecordAuditEvent::class);

        // A document that could not be built in full is not written (issue
        // #63). Left alone, Scramble exports what it has: a request whose
        // rules it could not read loses its body, and a type it could not
        // infer becomes an empty schema or a string. It reports both as
        // diagnostics, and the transformer below refuses a document that has
        // any. Set here, not as options of the command, so that
        // `composer openapi` and the contract test cannot disagree.
        Scramble::throwOnError();

        // Once: the rules are static, and the tests boot many applications.
        if (! Scramble::getSchemaValidator()->hasRules()) {
            Scramble::preventSchema(UnknownType::class, throw: false);
        }

        // Keeps the generated document identical on every machine: Scramble
        // builds the server URL from APP_URL and lists paths in route order.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document, OpenApiContext $context): void {
            $diagnostics = $context->diagnostics->all();

            if ($diagnostics->isNotEmpty()) {
                throw IncompleteOpenApiDocument::from($diagnostics);
            }

            $document->servers = [new Server('/'.$context->config->apiPath()->serverPath())];

            usort($document->paths, fn (Path $a, Path $b): int => $a->path <=> $b->path);
        });

        // A response documented as a file (`format: binary`, set by a
        // Response attribute on the controller) is not JSON as well: Scramble
        // infers a JSON body from the return type and keeps it next to the file.
        Scramble::configure()->withOperationTransformers(function (Operation $operation): void {
            foreach ($operation->responses ?? [] as $response) {
                if (! $response instanceof OpenApiResponse) {
                    continue;
                }

                foreach ($response->content as $schema) {
                    if ($schema instanceof Schema && $schema->type->format === 'binary') {
                        unset($response->content['application/json']);

                        break;
                    }
                }
            }
        });

        // Login attempts (issue #24). Keyed by what the client sent, never
        // by whether the account exists, so the limit reveals nothing. Each
        // realm counts on its own.
        RateLimiter::for('login', function (Request $request): array {
            $realm = $request->attributes->get(UseSessionRealm::ATTRIBUTE);
            $scope = $realm instanceof Realm ? $realm->name : 'none';

            $email = $request->input('email');
            $account = is_string($email) ? mb_strtolower(trim($email)) : '';
            $address = (string) $request->ip();

            return [
                Limit::perMinute(5)->by("account|$scope|$account|$address"),
                Limit::perMinute(20)->by("address|$scope|$address"),
            ];
        });

        // Second-factor attempts (issue #25): challenge, confirmation and
        // the two endpoints that take a password and a code. Counted per
        // account, so a new login does not reset it. Each realm counts on
        // its own.
        RateLimiter::for('two-factor', function (Request $request): array {
            $realm = $request->attributes->get(UseSessionRealm::ATTRIBUTE);
            $scope = $realm instanceof Realm ? $realm->name : 'none';

            $identifier = $request->user()?->getAuthIdentifier();
            $account = is_string($identifier) ? $identifier : '';
            $address = (string) $request->ip();

            return [
                Limit::perMinute(5)->by("two-factor|$scope|$account"),
                Limit::perMinute(20)->by("two-factor-address|$scope|$address"),
            ];
        });
    }
}
