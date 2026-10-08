<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/**
 * Who did it (decision 0013). The value is stored in rows that are never
 * rewritten, so it is a short stable name and never a class name. The two
 * account types double as the `resource` name of an account.
 */
enum ActorType: string
{
    case Administrator = 'administrator';
    case User = 'user';
    case Node = 'node';
    case System = 'system';

    public static function of(Administrator|User $account): self
    {
        return $account instanceof Administrator ? self::Administrator : self::User;
    }
}
