<?php

namespace App\Providers;

use App\Domain\Identity\TwoFactor\Totp;
use App\Http\Middleware\UseSessionRealm;
use App\Infrastructure\TwoFactor\Google2faTotp;
use App\Support\Realm;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
