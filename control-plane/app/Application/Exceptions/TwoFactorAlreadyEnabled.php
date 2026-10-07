<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

final class TwoFactorAlreadyEnabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Two-factor authentication is already enabled. Disable it first.');
    }
}
