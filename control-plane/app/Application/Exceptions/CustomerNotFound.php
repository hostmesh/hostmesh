<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * No customer has this address. The message names the address: it goes to
 * the operator who typed it, never to a client.
 */
final class CustomerNotFound extends RuntimeException
{
    public static function withEmail(string $email): self
    {
        return new self("No customer has the address $email.");
    }
}
