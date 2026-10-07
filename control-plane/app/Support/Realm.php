<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

/**
 * The session settings of one route group (spec section 28).
 */
final readonly class Realm
{
    public function __construct(
        public string $name,
        public string $guard,
        public string $origin,
        public string $sessionCookie,
        public string $sessionTable,
        public string $csrfCookie,
        public int $sessionLifetime,
        public string $cookieDomain,
        public bool $twoFactorRequired,
    ) {
        //
    }
}
