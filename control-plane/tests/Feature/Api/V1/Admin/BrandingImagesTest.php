<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\StoreBrandingImage;
use App\Application\Exceptions\InvalidBrandingImage;
use App\Domain\Audit\AuditLog;
use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\Images;
use Tests\Support\InMemoryAuditLog;

// Issue #29: logo and favicon uploads. Raster images only (no SVG until
// there is a sanitization decision). The type is read from the bytes, the
// file goes to a Laravel filesystem disk, and the client's file name and
// content type are never used. Every change is audited (decision 0013).

uses(RefreshDatabase::class);

const BRANDING_IMAGES = '/api/v1/admin/branding/images';

beforeEach(function (): void {
    $this->freezeTime();
    Storage::fake('branding');
});

/**
 * An administrator with the role, fully signed in.
 *
 * @return array{Administrator, Browser}
 */
function imageAdmin(mixed $test, ?Role $role = Role::Owner): array
{
    $factory = Administrator::factory()->withTwoFactor();
    $administrator = ($role === null ? $factory : $factory->withRoles($role))->create();

    $browser = new Browser($test);
    $browser->signIn(Browser::ADMIN, $administrator);

    return [$administrator, $browser];
}

/** @return list<string> every file on the branding disk */
function storedImages(): array
{
    return array_values(Storage::disk('branding')->allFiles());
}

/** @return list<string> the actions recorded after the two events of signing in */
function imageActions(): array
{
    return array_slice(AuditTrail::actions(), 2);
}

it('stores a logo under a name of its own and returns its URL', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);

    $url = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes, 'logo.png')])
        ->assertOk()
        ->assertJsonPath('data.logos.dark', null)
        ->json('data.logos.light');

    $version = substr(hash('sha256', $bytes), 0, 16);

    expect($url)->toBe("https://api.hostmesh.test/api/v1/branding/images/logo-light?v=$version")
        ->and(storedImages())->toBe(["logo-light/$version.png"])
        ->and(Storage::disk('branding')->get("logo-light/$version.png"))->toBe($bytes);

    $this->assertDatabaseHas('branding', ['id' => BrandingRecord::ID, 'logo_light' => "$version.png"]);
});

it('takes the extension from the bytes', function (string $slot, string $bytes, string $extension): void {
    [, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/'.$slot, ['file' => Images::upload($bytes, 'upload.bin', 'application/octet-stream')])
        ->assertOk();

    expect(storedImages())->toBe([$slot.'/'.substr(hash('sha256', $bytes), 0, 16).'.'.$extension]);
})->with([
    'a PNG logo' => ['logo-light', fn (): string => Images::png(64, 32), 'png'],
    'a JPEG logo' => ['logo-dark', fn (): string => Images::jpeg(), 'jpg'],
    'a WebP logo' => ['logo-dark', fn (): string => Images::webp(), 'webp'],
    'a PNG favicon' => ['favicon', fn (): string => Images::png(32, 32), 'png'],
]);

it('never uses the file name or the type the client sent', function (string $clientName, string $clientType): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-dark', ['file' => Images::upload($bytes, $clientName, $clientType)])
        ->assertOk();

    expect(storedImages())->toBe(['logo-dark/'.substr(hash('sha256', $bytes), 0, 16).'.png']);
})->with([
    'a path that climbs' => ['../../public/index.php', 'image/png'],
    'a script' => ['shell.php', 'application/x-php'],
    'two extensions' => ['logo.png.php', 'image/png'],
    'an SVG name on PNG bytes' => ['logo.svg', 'image/svg+xml'],
    'a null byte' => ["logo.php\0.png", 'image/png'],
    'no name' => ['', ''],
]);

it('refuses a file that is not an accepted raster image, whatever the client calls it', function (string $slot, string $bytes): void {
    [, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/'.$slot, ['file' => Images::upload($bytes, 'logo.png', 'image/png')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    expect(storedImages())->toBe([])
        ->and(BrandingRecord::current())->toBeNull()
        ->and(imageActions())->toBe([]);
})->with([
    'an SVG' => ['logo-light', fn (): string => Images::svg()],
    'an SVG with an XML declaration' => ['logo-light', fn (): string => '<?xml version="1.0"?>'.Images::svg()],
    'PHP source' => ['logo-light', fn (): string => '<?php echo 1;'],
    'HTML' => ['logo-light', fn (): string => '<html><body><script>alert(1)</script></body></html>'],
    'a GIF' => ['logo-light', fn (): string => Images::gif()],
    'a truncated PNG' => ['logo-light', fn (): string => substr(Images::png(64, 32), 0, 20)],
    'an empty file' => ['logo-light', fn (): string => ''],
    'a logo over 1 MB' => ['logo-light', fn (): string => Images::padded(Images::png(64, 32), 1_048_577)],
    'a logo wider than 2000' => ['logo-dark', fn (): string => Images::png(2001, 1)],
    'a logo higher than 2000' => ['logo-dark', fn (): string => Images::png(1, 2001)],
    'a JPEG favicon' => ['favicon', fn (): string => Images::jpeg()],
    'a favicon that is not square' => ['favicon', fn (): string => Images::png(32, 16)],
    'a favicon larger than 512' => ['favicon', fn (): string => Images::png(513, 513)],
    'a favicon over 256 KB' => ['favicon', fn (): string => Images::padded(Images::png(32, 32), 262_145)],
]);

it('accepts an image exactly at the limits', function (string $slot, string $bytes): void {
    [, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/'.$slot, ['file' => Images::upload($bytes)])->assertOk();
})->with([
    'a logo of 1 MB and 2000×2000' => ['logo-light', fn (): string => Images::padded(Images::png(2000, 2000), 1_048_576)],
    'a favicon of 256 KB and 512×512' => ['favicon', fn (): string => Images::padded(Images::png(512, 512), 262_144)],
]);

it('refuses a request without a file, or with text in its place', function (): void {
    [, $browser] = imageAdmin($this);

    $browser->post(Browser::ADMIN, BRANDING_IMAGES.'/logo-light')->assertUnprocessable()->assertJsonValidationErrors(['file']);
    $browser->post(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => 'logo.png'])->assertUnprocessable()->assertJsonValidationErrors(['file']);
});

it('answers 404 for a slot that does not exist', function (string $slot): void {
    [, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/'.$slot, ['file' => Images::upload(Images::png(64, 32))])->assertNotFound();
    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/'.$slot)->assertNotFound();

    expect(storedImages())->toBe([]);
})->with(['logo', 'logo_light', 'LOGO-LIGHT', 'banner']);

it('refuses an upload and a removal for an administrator who may only view', function (?Role $role): void {
    [, $browser] = imageAdmin($this, $role);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(64, 32))])->assertForbidden();
    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/logo-light')->assertForbidden();

    expect(storedImages())->toBe([]);
})->with(['read-only' => [Role::ReadOnly], 'no role' => [null]]);

it('creates the record with the default name when a logo is the first change', function (): void {
    [, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(64, 32))])
        ->assertOk()
        ->assertJsonPath('data.product_name', 'Hostmesh');

    $this->assertDatabaseCount('branding', 1);
});

it('serves a stored image to anyone, as the type its bytes have', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);
    $url = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes, 'logo.svg', 'image/svg+xml')])
        ->assertOk()->json('data.logos.light');

    $response = $this->get($url)->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($response->getContent())->toBe($bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
        ->and($response->headers->getCookies())->toBe([])
        ->and($cacheControl)->toContain('public')
        ->and($cacheControl)->toContain('max-age=31536000')
        ->and($cacheControl)->toContain('immutable')
        ->and((string) $response->headers->get('ETag'))->toBe('"'.substr(hash('sha256', $bytes), 0, 16).'"');

    $this->get($url, ['If-None-Match' => (string) $response->headers->get('ETag')])->assertStatus(304);

    $this->getJson('https://api.hostmesh.test/api/v1/branding')->assertOk()->assertJsonPath('data.logos.light', $url);
});

it('stores a PNG with PHP source appended as a PNG, and serves it as one', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32).'<?php echo "run"; ?>';

    $url = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes, 'logo.php', 'application/x-php')])
        ->assertOk()->json('data.logos.light');

    $response = $this->get($url)->assertOk();

    expect(storedImages())->toBe(['logo-light/'.substr(hash('sha256', $bytes), 0, 16).'.png'])
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toBe($bytes);
});

it('answers 404 for an image that is not set, and for a slot that does not exist', function (): void {
    $this->get('https://api.hostmesh.test/api/v1/branding/images/logo-light')->assertNotFound();
    $this->get('https://api.hostmesh.test/api/v1/branding/images/banner')->assertNotFound();
    $this->get('https://api.hostmesh.test/api/v1/branding/images/..%2F..%2F.env')->assertNotFound();
});

it('answers 404 when the stored file is gone from the disk', function (): void {
    [, $browser] = imageAdmin($this);
    $url = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/favicon', ['file' => Images::upload(Images::png(32, 32))])
        ->assertOk()->json('data.favicon');

    Storage::disk('branding')->deleteDirectory('favicon');

    $this->get($url)->assertNotFound();
});

it('replaces an image, deletes the old file and keeps serving a URL of the previous version', function (): void {
    [, $browser] = imageAdmin($this);
    $first = Images::png(64, 32);
    $second = Images::png(128, 64);

    $oldUrl = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($first)])->assertOk()->json('data.logos.light');
    $newUrl = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($second)])->assertOk()->json('data.logos.light');

    expect($newUrl)->not->toBe($oldUrl)
        ->and(storedImages())->toBe(['logo-light/'.substr(hash('sha256', $second), 0, 16).'.png']);

    // A response cached before the change still names the old version: it
    // shows the new logo, and is not kept for a year under the old URL.
    $stale = $this->get($oldUrl)->assertOk();
    $staleCacheControl = (string) $stale->headers->get('Cache-Control');

    expect($stale->getContent())->toBe($second)
        ->and($staleCacheControl)->toContain('max-age=300')
        ->and($staleCacheControl)->not->toContain('immutable');

    // The same without a version at all.
    expect((string) $this->get('https://api.hostmesh.test/api/v1/branding/images/logo-light')->assertOk()->headers->get('Cache-Control'))
        ->not->toContain('immutable');
});

it('keeps the images of the other slots when one is replaced', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes)])->assertOk();
    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-dark', ['file' => Images::upload($bytes)])->assertOk();
    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(128, 64))])->assertOk();

    $version = substr(hash('sha256', $bytes), 0, 16);

    expect(storedImages())->toContain("logo-dark/$version.png")
        ->and(storedImages())->not->toContain("logo-light/$version.png")
        ->and(storedImages())->toHaveCount(2);
});

it('removes an image and its file', function (): void {
    [, $browser] = imageAdmin($this);
    $url = $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-dark', ['file' => Images::upload(Images::png(64, 32))])
        ->assertOk()->json('data.logos.dark');

    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/logo-dark')->assertOk()->assertJsonPath('data.logos.dark', null);

    expect(storedImages())->toBe([]);

    $this->get($url)->assertNotFound();
    $this->assertDatabaseHas('branding', ['id' => BrandingRecord::ID, 'logo_dark' => null]);
});

it('records who replaced and who removed which image', function (): void {
    [$owner, $browser] = imageAdmin($this);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(64, 32), 'secret-client-name.png')])->assertOk();
    $replaced = AuditTrail::last();
    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/logo-light')->assertOk();
    $removed = AuditTrail::last();

    expect(imageActions())->toBe(['branding.image_replaced', 'branding.image_removed']);

    foreach ([$replaced, $removed] as $event) {
        expect($event->actor_type)->toBe('administrator')
            ->and($event->actor_id)->toBe($owner->id)
            ->and($event->resource)->toBe('branding')
            ->and($event->resource_id)->toBe(BrandingRecord::ID)
            ->and(AuditTrail::metadata($event))->toBe(['slot' => 'logo-light'])
            ->and($event->request_id)->not->toBeNull();
    }

    AuditTrail::assertHoldsNone('secret-client-name', $owner->email, $owner->name);
});

it('records nothing when the same image is uploaded again, or when there is nothing to remove', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes)])->assertOk();
    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes)])->assertOk();
    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/favicon')->assertOk()->assertJsonPath('data.favicon', null);

    expect(imageActions())->toBe(['branding.image_replaced'])
        ->and(storedImages())->toHaveCount(1);
});

it('stores no image when the audit log cannot be written', function (): void {
    [, $browser] = imageAdmin($this);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(64, 32))])->assertStatus(500);

    expect(storedImages())->toBe([])
        ->and(BrandingRecord::current())->toBeNull();
});

it('keeps the current image when its replacement or removal cannot be audited', function (): void {
    [, $browser] = imageAdmin($this);
    $bytes = Images::png(64, 32);
    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload($bytes)])->assertOk();
    $kept = 'logo-light/'.substr(hash('sha256', $bytes), 0, 16).'.png';
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->upload(Browser::ADMIN, BRANDING_IMAGES.'/logo-light', ['file' => Images::upload(Images::png(128, 64))])->assertStatus(500);
    $browser->delete(Browser::ADMIN, BRANDING_IMAGES.'/logo-light')->assertStatus(500);

    expect(storedImages())->toBe([$kept])
        ->and(BrandingRecord::current()?->logo_light)->toBe(basename($kept));
});

it('refuses a file in the application layer as well, whoever calls it', function (string $bytes): void {
    $upload = Images::upload($bytes);

    expect(fn () => app(StoreBrandingImage::class)->handle(null, ImageSlot::LogoLight, (string) $upload->getRealPath()))
        ->toThrow(InvalidBrandingImage::class)
        ->and(storedImages())->toBe([])
        ->and(BrandingRecord::current())->toBeNull();
})->with([
    'an SVG' => [fn (): string => Images::svg()],
    'PHP source' => [fn (): string => '<?php echo 1;'],
    'a PNG that is too wide' => [fn (): string => Images::png(2001, 1)],
]);

it('records an image stored by the system without an actor', function (): void {
    $upload = Images::upload(Images::png(32, 32));

    app(StoreBrandingImage::class)->handle(null, ImageSlot::Favicon, (string) $upload->getRealPath());

    $event = AuditTrail::last();

    expect($event->action)->toBe('branding.image_replaced')
        ->and($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBeNull()
        ->and(AuditTrail::metadata($event))->toBe(['slot' => 'favicon']);
});
