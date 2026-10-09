<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Branding\HexColor;

// Issue #29, spec section 28: brand colors are validated. A color is
// `#rrggbb`: six digits, no shorthand, no alpha channel.

it('parses a color and keeps it in lower case', function (): void {
    expect(HexColor::parse('#1D4ED8')->value)->toBe('#1d4ed8')
        ->and(HexColor::parse('#ffffff')->value)->toBe('#ffffff');
});

it('refuses anything that is not six hexadecimal digits after a hash', function (string $value): void {
    expect(fn (): HexColor => HexColor::parse($value))->toThrow(InvalidArgumentException::class);
})->with([
    'shorthand' => ['#fff'],
    'no hash' => ['ffffff'],
    'alpha channel' => ['#ffffff80'],
    'not hexadecimal' => ['#gggggg'],
    'a name' => ['red'],
    'empty' => [''],
    'a trailing newline' => ["#ffffff\n"],
    'a leading space' => [' #ffffff'],
]);

it('computes the WCAG contrast ratio', function (string $a, string $b, float $ratio): void {
    expect(round(HexColor::parse($a)->contrastWith(HexColor::parse($b)), 2))->toBe($ratio);
})->with([
    'black on white' => ['#000000', '#ffffff', 21.0],
    'white on black' => ['#ffffff', '#000000', 21.0],
    'a color on itself' => ['#1d4ed8', '#1d4ed8', 1.0],
    'the lightest grey that passes AA on white' => ['#767676', '#ffffff', 4.54],
    'one step lighter' => ['#777777', '#ffffff', 4.48],
    'blue on white' => ['#1d4ed8', '#ffffff', 6.7],
]);
