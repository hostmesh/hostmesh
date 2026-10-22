<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Domain\Identity\User;
use SensitiveParameter;

/**
 * What CreateCustomer hands back: the account, and its password in the
 * clear for the one moment it can be shown. Never store or log it.
 */
final readonly class NewCustomer
{
    public function __construct(
        public User $user,
        #[SensitiveParameter] public string $password,
    ) {
        //
    }
}
