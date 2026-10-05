<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\ContentSecurityPolicy;
use App\Support\Hostnames;
use App\Support\Realms;
use Illuminate\Support\Facades\File;

// Spec section 28: every hostname derives from one setting.

function setBaseDomain(string $domain): void
{
    putenv('HOSTMESH_DOMAIN='.$domain);
    $_ENV['HOSTMESH_DOMAIN'] = $domain;
    $_SERVER['HOSTMESH_DOMAIN'] = $domain;
}

afterEach(fn () => setBaseDomain('hostmesh.test'));

it('derives every hostname-dependent value from the base domain', function (string $domain): void {
    setBaseDomain($domain);
    $this->refreshApplication();

    expect(config('hostmesh.domain'))->toBe($domain)
        ->and(config('app.url'))->toBe("https://api.$domain")
        ->and(config('cors.allowed_origins'))->toBe(["https://app.$domain", "https://admin.$domain"])
        ->and(config('sanctum.stateful'))->toBe(["app.$domain", "admin.$domain"])
        ->and(config('filesystems.disks.public.url'))->toBe("https://api.$domain/storage")
        ->and(config('mail.mailers.smtp.local_domain'))->toBe("api.$domain")
        ->and(app(Hostnames::class)->agentHost())->toBe("agent.$domain")
        ->and(app(Realms::class)->get('admin')->origin)->toBe("https://admin.$domain")
        ->and(app(Realms::class)->get('admin')->cookieDomain)->toBe($domain)
        ->and(app(Realms::class)->get('customer')->origin)->toBe("https://app.$domain")
        ->and(app(Realms::class)->get('customer')->cookieDomain)->toBe($domain)
        ->and(app(ContentSecurityPolicy::class)->header('portal'))->toContain("connect-src https://api.$domain;")
        ->and(app(ContentSecurityPolicy::class)->header('admin'))->toContain("connect-src https://api.$domain;");
})->with(['hostmesh.test', 'example.org', 'hosting.example.co.uk']);

it('reads the base domain from the environment in exactly one place', function (): void {
    $files = collect(File::files(config_path()));

    // A read, not a mention: comments elsewhere may name the setting.
    $reads = fn ($file): bool => preg_match('/env\(\s*[\'"]HOSTMESH_DOMAIN/', $file->getContents()) === 1;

    expect($files->filter($reads)->map->getFilename()->values()->all())->toBe(['hostmesh.php']);

    foreach ($files as $file) {
        expect($file->getContents())
            ->not->toContain('APP_URL', 'SESSION_DOMAIN', 'SESSION_COOKIE', 'SANCTUM_STATEFUL_DOMAINS');

        // The development domain may appear only as the default of the one setting.
        if ($file->getFilename() !== 'hostmesh.php') {
            expect($file->getContents())->not->toContain('hostmesh.test');
        }
    }
});

it('refuses to boot with a malformed base domain', function (): void {
    setBaseDomain('https://example.com');

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidArgumentException::class, 'HOSTMESH_DOMAIN');
});
