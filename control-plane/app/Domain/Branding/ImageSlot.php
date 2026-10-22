<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * The images of the branding record (spec section 28).
 * The value is part of the API.
 */
enum ImageSlot: string
{
    case LogoLight = 'logo-light';
    case LogoDark = 'logo-dark';
    case Favicon = 'favicon';

    /**
     * The raster types a slot can hold and the extension each is stored
     * under. No SVG (decision 0020).
     */
    public const array EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * The column of `branding` that holds the stored file name.
     */
    public function column(): string
    {
        return match ($this) {
            self::LogoLight => 'logo_light',
            self::LogoDark => 'logo_dark',
            self::Favicon => 'favicon',
        };
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return $this === self::Favicon ? ['image/png'] : array_keys(self::EXTENSIONS);
    }

    public function maxBytes(): int
    {
        return $this === self::Favicon ? 256 * 1024 : 1024 * 1024;
    }

    /** The largest width and height, in pixels. */
    public function maxDimension(): int
    {
        return $this === self::Favicon ? 512 : 2000;
    }

    /** Where a stored file of this slot lives on the `branding` disk. */
    public function path(string $name): string
    {
        return $this->value.'/'.$name;
    }

    /** Why this slot refuses the image, as a sentence for the administrator, or null when it accepts it. */
    public function violation(InspectedImage $image): ?string
    {
        if (! in_array($image->mimeType, $this->mimeTypes(), true)) {
            return $this === self::Favicon
                ? 'The favicon must be a PNG file.'
                : 'The logo must be a PNG, JPEG or WebP file.';
        }

        if ($image->bytes > $this->maxBytes()) {
            return 'The image must not be larger than '.intdiv($this->maxBytes(), 1024).' KB.';
        }

        $max = $this->maxDimension();

        if ($image->width < 1 || $image->height < 1 || $image->width > $max || $image->height > $max) {
            return "The image must not be larger than $max by $max pixels.";
        }

        if ($this === self::Favicon && $image->width !== $image->height) {
            return 'The favicon must be square.';
        }

        return null;
    }
}
