<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Domain\Branding\HexColor;
use InvalidArgumentException;

/**
 * Everything UpdateBranding replaces: the name, the four colors and the
 * three links. Images have commands of their own. A color or a link that is
 * null is cleared.
 */
final readonly class BrandingChanges
{
    public function __construct(
        public string $productName,
        public ?string $primaryLight,
        public ?string $primaryForegroundLight,
        public ?string $primaryDark,
        public ?string $primaryForegroundDark,
        public ?string $supportUrl,
        public ?string $termsUrl,
        public ?string $privacyUrl,
    ) {
        //
    }

    /**
     * The columns of `branding` and their new values. Colors are stored
     * in lower case.
     *
     * @return array<string, string|null>
     *
     * @throws InvalidArgumentException for a color that is not `#rrggbb`
     */
    public function attributes(): array
    {
        return [
            'product_name' => $this->productName,
            'primary_light' => self::color($this->primaryLight),
            'primary_foreground_light' => self::color($this->primaryForegroundLight),
            'primary_dark' => self::color($this->primaryDark),
            'primary_foreground_dark' => self::color($this->primaryForegroundDark),
            'support_url' => $this->supportUrl,
            'terms_url' => $this->termsUrl,
            'privacy_url' => $this->privacyUrl,
        ];
    }

    protected static function color(?string $value): ?string
    {
        return $value === null ? null : HexColor::parse($value)->value;
    }
}
