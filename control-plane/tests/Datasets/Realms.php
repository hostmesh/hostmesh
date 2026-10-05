<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Tests\Support\Browser;

// origin, route prefix, session cookie, CSRF cookie, idle lifetime in minutes
dataset('realms', [
    'admin' => [Browser::ADMIN, '/api/v1/admin', Browser::ADMIN_SESSION, Browser::ADMIN_CSRF, 30],
    'portal' => [Browser::PORTAL, 'api/v1/customer', Browser::PORTAL_SESSION, Browser::PORTAL_CSRF, 120],
]);
