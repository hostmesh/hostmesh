<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use SensitiveParameter;

/** What the account needs to set up its authenticator app. */
final readonly class TwoFactorEnrollment
{
    public function __construct(
        #[SensitiveParameter] public string $secret,
        #[SensitiveParameter] public string $provisioningUri,
    ) {
        //
    }
}
