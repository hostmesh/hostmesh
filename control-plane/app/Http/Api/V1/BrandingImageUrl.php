<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Branding\ImageSlot;

final class BrandingImageUrl
{
    /**
     * The public URL of a stored image, or null when the slot is empty.
     * The path names the slot, so a URL from a cached response keeps
     * working after the image is replaced. `v` is the version of the file:
     * it changes with the content.
     */
    public static function of(ImageSlot $slot, ?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        return url('/api/v1/branding/images/'.$slot->value).'?v='.pathinfo($file, PATHINFO_FILENAME);
    }
}
