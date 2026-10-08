<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

use InvalidArgumentException;

/**
 * A brand color as `#rrggbb` (spec section 28). No shorthand and no alpha
 * channel: a contrast ratio needs an opaque color.
 */
final readonly class HexColor
{
    public const string PATTERN = '/\A#[0-9a-fA-F]{6}\z/';

    protected function __construct(
        public string $value,
    ) {
        //
    }

    public static function parse(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A color has the form #rrggbb.');
        }

        return new self(strtolower($value));
    }

    /** WCAG 2.2, "relative luminance". */
    public function relativeLuminance(): float
    {
        $linear = [];

        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($this->value, $offset, 2)) / 255;
            $linear[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** WCAG 2.2, "contrast ration": from 1 (the same color) to 21 (black on white). */
    public function contrastWith(self $other): float
    {
        $a = $this->relativeLuminance();
        $b = $other->relativeLuminance();

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
