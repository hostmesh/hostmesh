<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

// Issue #161, spec section 28: the versioned API is the only external
// interface. Every route is one the application defines: under /api/v1 and
// handled by a controller in App\Http\Api\V1. A route that the framework or
// a package registers by itself, such as Laravel's storage/{path}, fails here.

it('registers only the routes the application defines', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());

    $foreign = $routes
        ->reject(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/')
            && str_starts_with($route->getActionName(), 'App\\Http\\Api\\V1\\'))
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri().' '.$route->getActionName())
        ->values()
        ->all();

    expect($routes->map(fn (RoutingRoute $route): string => $route->uri()))->toContain('api/v1/health')
        ->and($foreign)->toBe([]);
});
