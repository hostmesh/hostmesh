<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Server;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keeps the generated document identical on every machine: Scramble
        // builds the server URL from APP_URL and lists paths in route order.
        Scramble::configure()->withDocumentTransformers(function (OpenApi $document): void {
            $document->servers = [new Server('/'.config()->string('scramble.api_path'))];

            usort($document->paths, fn (Path $a, Path $b): int => $a->path <=> $b->path);
        });
    }
}
