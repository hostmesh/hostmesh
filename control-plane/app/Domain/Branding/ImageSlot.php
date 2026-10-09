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
}
