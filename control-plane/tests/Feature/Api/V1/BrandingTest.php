<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Branding\ImageSlot;
use App\Http\Api\V1\BrandingImageUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;
use Tests\Support\StoredBranding;

// Issue #29, spec sections 28 and 37: `GET /api/v1/branding` is public and
// cacheable. It returns the product name, light and dark logos, favicon,
// brand colors, and support and legal links, with defaults when nothing is
// configured.

uses(RefreshDatabase::class);

const PUBLIC_BRANDING = 'https://api.hostmesh.test/api/v1/branding';

it('answers with the defaults when nothing is configured', function (): void {
    $this->getJson(PUBLIC_BRANDING)->assertOk()->assertExactJson(['data' => [
        'product_name' => 'Hostmesh',
        'logos' => ['light' => null, 'dark' => null],
        'favicon' => null,
        'colors' => [
            'light' => ['primary' => null, 'primary_foreground' => null],
            'dark' => ['primary' => null, 'primary_foreground' => null],
        ],
        'links' => ['support' => null, 'terms' => null, 'privacy' => null],
    ]]);

    $this->assertDatabaseCount('branding', 0);
});

it('returns what is stored', function (): void {
    StoredBranding::with([
        'product_name' => 'Acme Hosting',
        'logo_light' => 'aaaaaaaaaaaaaaaa.png',
        'favicon' => 'bbbbbbbbbbbbbbbb.png',
        'primary_light' => '#1d4ed8',
        'primary_foreground_light' => '#ffffff',
        'primary_dark' => '#60a5fa',
        'support_url' => 'https://acme.example/help',
        'privacy_url' => 'https://acme.example/privacy',
    ]);

    $this->getJson(PUBLIC_BRANDING)->assertOk()->assertExactJson(['data' => [
        'product_name' => 'Acme Hosting',
        'logos' => [
            'light' => 'https://api.hostmesh.test/api/v1/branding/images/logo-light?v=aaaaaaaaaaaaaaaa',
            'dark' => null,
        ],
        'favicon' => 'https://api.hostmesh.test/api/v1/branding/images/favicon?v=bbbbbbbbbbbbbbbb',
        'colors' => [
            'light' => ['primary' => '#1d4ed8', 'primary_foreground' => '#ffffff'],
            'dark' => ['primary' => '#60a5fa', 'primary_foreground' => null],
        ],
        'links' => ['support' => 'https://acme.example/help', 'terms' => null, 'privacy' => 'https://acme.example/privacy'],
    ]]);
});

it('builds the URL of an image from its slot and version', function (): void {
    expect(BrandingImageUrl::of(ImageSlot::LogoDark, '0123456789abcdef.webp'))
        ->toBe('https://api.hostmesh.test/api/v1/branding/images/logo-dark?v=0123456789abcdef')
        ->and(BrandingImageUrl::of(ImageSlot::LogoDark, null))->toBeNull();
});

it('needs no session and sets no cookie', function (): void {
    $response = $this->getJson(PUBLIC_BRANDING)->assertOk();

    expect($response->headers->getCookies())->toBe([]);
});

it('is cacheable by anyone for five minutes and carries an ETag', function (): void {
    $response = $this->getJson(PUBLIC_BRANDING)->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('max-age=300')
        ->and($cacheControl)->not->toContain('private')
        ->and($cacheControl)->not->toContain('no-cache')
        ->and((string) $response->headers->get('ETag'))->toMatch('/\A"[0-9a-f]{32}"\z/');
});

it('answers 304 without a body to a matching If-None-Match', function (): void {
    $etag = (string) $this->getJson(PUBLIC_BRANDING)->headers->get('ETag');

    $response = $this->getJson(PUBLIC_BRANDING, ['If-None-Match' => $etag])->assertStatus(304);

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag);
});

it('answers 200 to an If-None-Match that no longer matches', function (): void {
    $etag = (string) $this->getJson(PUBLIC_BRANDING)->headers->get('ETag');

    StoredBranding::with(['product_name' => 'Acme Hosting']);

    $response = $this->getJson(PUBLIC_BRANDING, ['If-None-Match' => $etag])->assertOk();

    expect($response->headers->get('ETag'))->not->toBe($etag);
});

it('can be read from both SPA origins, and caches must keep them apart', function (string $origin): void {
    $response = $this->getJson(PUBLIC_BRANDING, ['Origin' => $origin])->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe($origin)
        ->and((string) $response->headers->get('Vary'))->toContain('Origin');
})->with([Browser::ADMIN, Browser::PORTAL]);
