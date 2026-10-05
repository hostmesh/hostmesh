<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

// Sanctum's cookie-based SPA authentication (spec section 28). Sanctum has
// one stateful list and one guard list for the whole application, so
// App\Http\Middleware\UseSessionRealm narrows both per route group.

return [
    // Set by HostnameServiceProvider from HOSTMESH_DOMAIN.
    'stateful' => [],

    // Set per request to the guard of the route group. Empty here, so that
    // nothing authenticates outside a realm.
    'guard' => [],

    'expiration' => null,

    'token_prefix' => '',

    // Sanctum's /sanctum/csrf-cookie knows no realm. Each route group has
    // its own auth/csrf-cookie.
    'routes' => false,

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],
];
