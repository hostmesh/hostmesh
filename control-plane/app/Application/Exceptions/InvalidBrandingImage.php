<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/** A file that a branding image slot refuses. The message is a sentence for the administrator. */
final class InvalidBrandingImage extends RuntimeException
{
    public const string NOT_AN_IMAGE = 'The file is not an image.';
}
