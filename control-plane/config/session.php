<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Only the two realm route groups start a session (spec section 28).
// App\Http\Middleware\UseSessionRealm sets `cookie`, `table`, and `lifetime`
// for the request from config/hostmesh.php; the values below are the
// defaults for anything it does not set.

return [

    // PostgreSQL is authoritative (spec section 23). Not configurable.
    'driver' => 'database',
    'connection' => null,
    'table' => 'portal_sessions',
    'store' => null,

    // Idle minutes. Overridden per realm.
    'lifetime' => 120,
    'expire_on_close' => false,

    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'lottery' => [2, 100],
    'serialization' => 'json',

    // Cookie defaults: host-only on the API host, HTTPS only, not readable
    // by JavaScript. The __Host- prefix makes the browser enforce the first
    // two. Overridden per realm.
    'cookie' => '__Host-hostmesh-session',
    'path' => '/',
    'domain' => null,
    'secure' => true,
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
