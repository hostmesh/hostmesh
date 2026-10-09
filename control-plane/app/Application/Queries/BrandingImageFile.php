<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

/** A stored branding image, ready to send. `version` changes with the content. */
final readonly class BrandingImageFile
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $version,
    ) {
        //
    }
}
