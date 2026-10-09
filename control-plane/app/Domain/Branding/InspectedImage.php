<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

/** What the bytes of a file are, whatever its name or the client says. */
final readonly class InspectedImage
{
    public function __construct(
        public string $mimeType,
        public int $width,
        public int $height,
        public int $bytes,
    ) {
        //
    }
}
