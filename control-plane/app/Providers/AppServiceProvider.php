<?php

// SPDX-License-Identifier: Apache-2.0 AND MIT
// Derived from the Laravel application skeleton (laravel/laravel, MIT).

namespace App\Providers;

use App\Application\Events\Audited;
use App\Application\Listeners\RecordAuditEvent;
use App\Application\Services\AuditContext;
use App\Application\Services\LoginAddressHasher;
use App\Console\EndToEnd\Environment;
use App\Console\EndToEnd\SeedCommand;
use App\Console\Smoke\QueueCommand;
use App\Domain\Audit\AuditEventRecord;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Policies\AuditEventPolicy;
use App\Domain\Audit\RetentionPolicy;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\Policies\BrandingPolicy;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\Policies\AdministratorPolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use App\Domain\Provisioning\Jitter;
use App\Domain\Provisioning\RetryPolicy;
use App\Domain\Provisioning\SweepPolicy;
use App\Http\Middleware\UseSessionRealm;
use App\Infrastructure\Audit\DatabaseAuditLog;
use App\Infrastructure\OpenApi\IncompleteOpenApiDocument;
use App\Infrastructure\Provisioning\RandomJitter;
use App\Infrastructure\TwoFactor\Google2faTotp;
use App\Support\Realm;
use App\Support\TrustedProxies;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;

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

        // Read by Laravel's TrustProxies middleware (decision 0018). Always a
        // list, also an empty one: left null, the middleware trusts every
        // address on some hosting platforms' hostnames.
        $trustedProxies = config('hostmesh.trusted_proxies');
        config(['trustedproxy.proxies' => TrustedProxies::fromSetting(is_string($trustedProxies) ? $trustedProxies : '')->addresses]);

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

        // Decision 0023: the breach check of a new password. Laravel waits
        // 30 seconds before it gives up and accepts the password; a password
        // change should not hang that long on a third party. Extended rather
        // than bound: Laravel's validation provider is deferred, and its
        // binding would replace this one when it loads.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn (UncompromisedVerifier $verifier, Application $app): NotPwnedVerifier => new NotPwnedVerifier($app->make(HttpFactory::class), 5),
        );

        $this->app->bind(
            SweepPolicy::class,
            fn (Application $app): SweepPolicy => new SweepPolicy($app->make(Repository::class)->integer('hostmesh.jobs.sweep_after')),
        );

        // Spec section 21: both periods are configurable defaults. Built on
        // each use, like the retry policy.
        $this->app->bind(RetentionPolicy::class, function (Application $app): RetentionPolicy {
            $config = $app->make(Repository::class);

            return new RetentionPolicy(
                $config->integer('hostmesh.audit.retention.event_months'),
                $config->integer('hostmesh.audit.retention.address_days'),
                $config->integer('hostmesh.audit.retention.batch_size'),
                $config->integer('hostmesh.audit.retention.run_seconds'),
            );
        });
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
        // builds the server URL from APP_URL and lists paths, shared
        // responses and tags in the order it meets them (issue #160).
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document, OpenApiContext $context): void {
            $diagnostics = $context->diagnostics->all();

            if ($diagnostics->isNotEmpty()) {
                throw IncompleteOpenApiDocument::from($diagnostics);
            }

            $document->servers = [new Server('/'.$context->config->apiPath()->serverPath())];

            usort($document->paths, fn (Path $a, Path $b): int => $a->path <=> $b->path);

            // Every route is on the API host, under the one server above. A
            // route bound to a domain would get a server of its own, built
            // with the scheme of APP_URL.
            foreach ($document->paths as $path) {
                $path->servers = [];

                foreach ($path->operations as $operation) {
                    $operation->servers = [];
                }
            }

            // Keyed by class name and published under its short name, as
            // the schemas are, which Scramble sorts itself.
            uksort(
                $document->components->responses,
                fn (string $a, string $b): int => [class_basename($a), $a] <=> [class_basename($b), $b],
            );

            // By the weight of their #[Group], then by name: Scramble sorts
            // by weight only and keeps route order among equals.
            usort($document->tags, fn (Tag $a, Tag $b): int => [$a->getAttribute('weight', PHP_INT_MAX), $a->name]
                <=> [$b->getAttribute('weight', PHP_INT_MAX), $b->name]);
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

        // The two public branding routes (issue #158), counted together by
        // client address. Generous: a login page loads the branding and an
        // image or two, and many people may share one address
        // (control-plane/README.md, "Rate limits").
        // Decision 0023: changing a password, like the two-factor actions,
        // per account and per address, and on its own budget.
        RateLimiter::for('password', function (Request $request): array {
            $realm = $request->attributes->get(UseSessionRealm::ATTRIBUTE);
            $scope = $realm instanceof Realm ? $realm->name : 'none';

            $identifier = $request->user()?->getAuthIdentifier();
            $account = is_string($identifier) ? $identifier : '';
            $address = (string) $request->ip();

            return [
                Limit::perMinute(5)->by("password|$scope|$account"),
                Limit::perMinute(20)->by("password-address|$scope|$address"),
            ];
        });

        RateLimiter::for('branding', fn (Request $request): Limit => Limit::perMinute(300)->by('branding|'.$request->ip()));

        // Issue #32: the seed command of the Playwright suite exists only
        // in the end-to-end and test environments. It is outside
        // app/Console/Commands on purpose, so Laravel does not discover it.
        if (Environment::allows($this->app->environment())) {
            $this->commands([SeedCommand::class]);
        }

        // Issue #156: the queue probe of the development stack's smoke test.
        // Outside app/Console/Commands for the same reason.
        if (in_array($this->app->environment(), QueueCommand::ENVIRONMENTS, true)) {
            $this->commands([QueueCommand::class]);
        }
    }
}
