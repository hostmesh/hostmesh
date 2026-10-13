<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * An administrator with this address exists already. The message names
 * the address: it goes to the operator who typed it, never to a client.
 */
final class AdministratorExists extends RuntimeException
{
    public static function withEmail(string $email): self
    {
        return new self("An administrator with the address $email already exists.");
    }
}
