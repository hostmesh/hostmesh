<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/** The logged-in account did not confirm an action with its own password. */
final class IncorrectPassword extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The password is incorrect.');
    }
}
