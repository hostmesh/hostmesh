<?php

// SPDX-License-Identifier: Apache-2.0 AND MIT
// Derived from the Laravel application skeleton (laravel/laravel, MIT).

use App\Http\Middleware\CaptureRequestContext;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\UseSessionRealm;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the group, so that a request refused further in still
        // gets its X-Request-Id.
        $middleware->api(prepend: [CaptureRequestContext::class]);

        // Decision 0018: from a trusted proxy (HOSTMESH_TRUSTED_PROXIES),
        // only the client address. Host, scheme, port and prefix stay what
        // the web server says.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);

        $middleware->alias([
            // The first middleware of each realm's route group (routes/api.php).
            'realm' => UseSessionRealm::class,
            // After auth:<guard>: the session must have completed the second factor.
            'two-factor' => RequireSecondFactor::class,
        ]);

        // The gate answers before a route parameter is resolved, so half a
        // login learns nothing about what exists.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireSecondFactor::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
