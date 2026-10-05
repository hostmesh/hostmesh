<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * The one answer to every failed login. It does not say whether the email
 * exists (no user enumeration).
 */
final class InvalidCredentials extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('These credentials do not match our records.');
    }
}
