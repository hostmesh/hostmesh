<?php

namespace App\Providers;

use App\Application\Events\Audited;
use App\Application\Listeners\RecordAuditEvent;
use App\Application\Services\AuditContext;
use App\Application\Services\LoginAddressHasher;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\Policies\AdministratorPolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use App\Http\Middleware\UseSessionRealm;
use App\Infrastructure\Audit\DatabaseAuditLog;
use App\Infrastructure\TwoFactor\Google2faTotp;
use App\Support\Realm;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
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

        // Decision 0013: every event that implements Audited is written to
        // the audit log, synchronously, inside the caller's transaction.
        Event::listen(Audited::class, RecordAuditEvent::class);

        // Keeps the generated document identical on every machine: Scramble
        // builds the server URL from APP_URL and lists paths in route order.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document, OpenApiContext $context): void {
            $document->servers = [new Server('/'.$context->config->apiPath()->serverPath())];

            usort($document->paths, fn (Path $a, Path $b): int => $a->path <=> $b->path);
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
