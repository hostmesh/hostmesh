<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use SensitiveParameter;

/**
 * The keyed hash of a submitted login address (decision 0013). People type
 * passwords into the email field, so the address of a failed login for an
 * unknown account is never stored. The key is derived from the
 * application key: rotating that key ends the correlation with earlier
 * attempts.
 */
final readonly class LoginAddressHasher
{
    protected const string PURPOSE = 'hostmesh.audit.login-address';

    public function __construct(
        #[SensitiveParameter] protected string $applicationKey,
    ) {
        //
    }

    public function hash(#[SensitiveParameter] string $address): string
    {
        return hash_hmac(
            'sha256',
            mb_strtolower(trim($address)),
            hash_hkdf('sha256', $this->applicationKey, 32, self::PURPOSE),
        );
    }
}
