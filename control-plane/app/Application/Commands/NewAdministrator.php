<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Domain\Identity\Administrator;
use SensitiveParameter;

/**
 * What CreateAdministrator hands back: the account, and its password in
 * the clear for the one moment it can be shown. Never store or log it.
 */
final readonly class NewAdministrator
{
    public function __construct(
        public Administrator $administrator,
        #[SensitiveParameter] public string $password,
    ) {
        //
    }
}
