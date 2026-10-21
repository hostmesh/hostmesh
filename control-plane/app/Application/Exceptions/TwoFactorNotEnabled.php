<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * The administrator has no second factor to reset. The message names the
 * address: it goes to the operator who typed it, never to a client.
 */
final class TwoFactorNotEnabled extends RuntimeException
{
    public static function forAdministrator(string $email): self
    {
        return new self("The administrator $email has no second factor. The next login sets one up.");
    }
}
