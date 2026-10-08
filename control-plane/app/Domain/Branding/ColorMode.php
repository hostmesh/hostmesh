<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

enum ColorMode: string
{
    case Light = 'light';
    case Dark = 'dark';

    /**
     * The tokens a brand color is checked against, as sRGB. They mirror
     * web/packages/ui/src/styles/tokens.css, which defines them in oklch:
     * change both together.
     *
     * @return array{background: string, card: string, primary: string, primary_foreground: string}
     */
    public function defaultTokens(): array
    {
        return match ($this) {
            self::Light => ['background' => '#ffffff', 'card' => '#ffffff', 'primary' => '#171717', 'primary_foreground' => '#fafafa'],
            self::Dark => ['background' => '#0a0a0a', 'card' => '#171717', 'primary' => '#e5e5e5', 'primary_foreground' => '#171717'],
        };
    }
}
