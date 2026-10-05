<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Spec section 28: CORS allows exactly the two SPA origins, with
// credentials, because the apps authenticate with cookies. Any other origin
// gets no Access-Control-* header, so the browser refuses the response.

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    // Set by HostnameServiceProvider from HOSTMESH_DOMAIN. Never '*' and
    // never a pattern: with credentials allowed, each origin is spelled out.
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    // Seconds a browser may cache a preflight answer.
    'max_age' => 600,

    'supports_credentials' => true,
];
