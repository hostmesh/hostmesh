<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * The one answer to every refused code: wrong, expired, already used, or
 * no two-factor to check it against.
 */
final class InvalidTwoFactorCode extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The two-factor code is invalid.');
    }
}
