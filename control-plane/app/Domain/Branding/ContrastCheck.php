<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * Which pairs of one mode are checked (spec sections 28 and 37): the
 * foreground on the primary, and the primary on the default background and
 * card. A color that is not set is the default token.
 */
final class ContrastCheck
{
    /** WCAG AA for normal text. */
    public const float REQUIRED = 4.5;

    /** @return list<ContrastWarning> */
    public static function warnings(ColorMode $mode, ?string $primary, ?string $primaryForeground): array
    {
        if ($primary === null && $primaryForeground === null) {
            return [];
        }

        $defaults = $mode->defaultTokens();
        $effectivePrimary = HexColor::parse($primary ?? $defaults['primary']);

        $pairs = [
            ['primary_foreground', HexColor::parse($primaryForeground ?? $defaults['primary_foreground']), 'primary', $effectivePrimary],
        ];

        if ($primary !== null) {
            $pairs[] = ['primary', $effectivePrimary, 'background', HexColor::parse($defaults['background'])];
            $pairs[] = ['primary', $effectivePrimary, 'card', HexColor::parse($defaults['card'])];
        }

        $warnings = [];

        foreach ($pairs as [$color, $value, $against, $surface]) {
            $ratio = $value->contrastWith($surface);

            if ($ratio < self::REQUIRED) {
                // Rounded down: 4.498 must not read as 4.5.
                $warnings[] = new ContrastWarning($mode, $color, $against, floor($ratio * 100) / 100, self::REQUIRED);
            }
        }

        return $warnings;
    }
}
