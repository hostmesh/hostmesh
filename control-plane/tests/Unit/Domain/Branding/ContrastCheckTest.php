<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Branding\ColorMode;
use App\Domain\Branding\ContrastCheck;
use App\Domain\Branding\ContrastWarning;

// Issue #29, spec sections 28 and 37: a contrast warning for a color that
// fails WCAG AA (4.5:1) against the default surfaces. Per mode, the pairs
// are primary_foreground on primary, and primary on background and on card.

/** @return list<array{string, string, float}> color, against, ratio */
function failedPairs(ColorMode $mode, ?string $primary, ?string $primaryForeground): array
{
    return array_map(
        fn (ContrastWarning $warning): array => [$warning->color, $warning->against, $warning->ratio],
        ContrastCheck::warnings($mode, $primary, $primaryForeground),
    );
}

it('mirrors the default tokens of the UI package', function (): void {
    // web/packages/ui/src/styles/tokens.css, converted from oklch to sRGB.
    expect(ColorMode::Light->defaultTokens())->toBe([
        'background' => '#ffffff', 'card' => '#ffffff', 'primary' => '#171717', 'primary_foreground' => '#fafafa',
    ])->and(ColorMode::Dark->defaultTokens())->toBe([
        'background' => '#0a0a0a', 'card' => '#171717', 'primary' => '#e5e5e5', 'primary_foreground' => '#171717',
    ]);
});

it('checks nothing in a mode that keeps both defaults', function (ColorMode $mode): void {
    expect(ContrastCheck::warnings($mode, null, null))->toBe([]);
})->with([ColorMode::Light, ColorMode::Dark]);

it('passes a pair that reads well on every surface', function (ColorMode $mode, string $primary, string $foreground): void {
    expect(ContrastCheck::warnings($mode, $primary, $foreground))->toBe([]);
})->with([
    'light' => [ColorMode::Light, '#1d4ed8', '#ffffff'],
    'dark' => [ColorMode::Dark, '#60a5fa', '#0a0a0a'],
]);

it('warns for a primary that fails on the surfaces and under the default foreground', function (): void {
    $pairs = failedPairs(ColorMode::Light, '#ffff00', null);

    expect(array_map(fn (array $pair): array => [$pair[0], $pair[1]], $pairs))->toBe([
        ['primary_foreground', 'primary'],
        ['primary', 'background'],
        ['primary', 'card'],
    ])->and($pairs[1][2])->toBe(1.07);
});

it('warns for a primary that only fails on the dark surfaces', function (): void {
    expect(failedPairs(ColorMode::Dark, '#1d4ed8', '#ffffff'))->toBe([
        ['primary', 'background', 2.95],
        ['primary', 'card', 2.67],
    ]);
});

it('checks a foreground on its own against the default primary, and no surface', function (): void {
    $pairs = failedPairs(ColorMode::Light, null, '#777777');

    expect($pairs)->toHaveCount(1)
        ->and([$pairs[0][0], $pairs[0][1]])->toBe(['primary_foreground', 'primary'])
        ->and($pairs[0][2])->toBeLessThan(4.5);
});

it('never rounds a failing ratio up to the threshold', function (): void {
    // #777777 on white is 4.478: reported as 4.47, not 4.48 and never 4.5.
    $warning = ContrastCheck::warnings(ColorMode::Light, '#777777', '#ffffff')[1];

    expect($warning->against)->toBe('background')
        ->and($warning->ratio)->toBe(4.47)
        ->and($warning->required)->toBe(4.5);
});

it('describes a warning for the API', function (): void {
    expect(ContrastCheck::warnings(ColorMode::Dark, '#1d4ed8', '#ffffff')[0]->toArray())->toBe([
        'mode' => 'dark', 'color' => 'primary', 'against' => 'background', 'ratio' => 2.95, 'required' => 4.5,
    ]);
});
