<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Branding\ImageSlot;
use App\Domain\Branding\InspectedImage;

// Issue #29: raster images only. Logos are PNG, JPEG or WebP, at most 1 MB
// and 2000×2000. The favicon is a square PNG, at most 256 KB and 512×512.

it('names the column and the directory of each slot', function (): void {
    expect(ImageSlot::LogoLight->column())->toBe('logo_light')
        ->and(ImageSlot::LogoDark->column())->toBe('logo_dark')
        ->and(ImageSlot::Favicon->column())->toBe('favicon')
        ->and(ImageSlot::LogoDark->path('0123456789abcdef.png'))->toBe('logo-dark/0123456789abcdef.png');
});

it('accepts an image within the limits of its slot', function (ImageSlot $slot, InspectedImage $image): void {
    expect($slot->violation($image))->toBeNull();
})->with([
    'a PNG logo' => [ImageSlot::LogoLight, new InspectedImage('image/png', 400, 120, 20_000)],
    'a JPEG logo' => [ImageSlot::LogoDark, new InspectedImage('image/jpeg', 400, 120, 20_000)],
    'a WebP logo' => [ImageSlot::LogoLight, new InspectedImage('image/webp', 400, 120, 20_000)],
    'the largest logo' => [ImageSlot::LogoLight, new InspectedImage('image/png', 2000, 2000, 1_048_576)],
    'the largest favicon' => [ImageSlot::Favicon, new InspectedImage('image/png', 512, 512, 262_144)],
    'the smallest favicon' => [ImageSlot::Favicon, new InspectedImage('image/png', 1, 1, 70)],
]);

it('refuses an image outside the limits of its slot', function (ImageSlot $slot, InspectedImage $image, string $reason): void {
    expect($slot->violation($image))->toContain($reason);
})->with([
    'an SVG logo' => [ImageSlot::LogoLight, new InspectedImage('image/svg+xml', 1, 1, 100), 'PNG, JPEG or WebP'],
    'a GIF logo' => [ImageSlot::LogoLight, new InspectedImage('image/gif', 2, 2, 35), 'PNG, JPEG or WebP'],
    'an icon as favicon' => [ImageSlot::Favicon, new InspectedImage('image/vnd.microsoft.icon', 32, 32, 300), 'PNG'],
    'a JPEG favicon' => [ImageSlot::Favicon, new InspectedImage('image/jpeg', 32, 32, 300), 'PNG'],
    'a logo one byte too large' => [ImageSlot::LogoLight, new InspectedImage('image/png', 400, 120, 1_048_577), '1024 KB'],
    'a favicon one byte too large' => [ImageSlot::Favicon, new InspectedImage('image/png', 32, 32, 262_145), '256 KB'],
    'a logo too wide' => [ImageSlot::LogoLight, new InspectedImage('image/png', 2001, 100, 500), '2000'],
    'a logo too high' => [ImageSlot::LogoLight, new InspectedImage('image/png', 100, 2001, 500), '2000'],
    'a favicon too large' => [ImageSlot::Favicon, new InspectedImage('image/png', 513, 513, 500), '512'],
    'a favicon that is not square' => [ImageSlot::Favicon, new InspectedImage('image/png', 32, 16, 500), 'square'],
    'an image without a size' => [ImageSlot::LogoLight, new InspectedImage('image/png', 0, 0, 500), '2000'],
]);
