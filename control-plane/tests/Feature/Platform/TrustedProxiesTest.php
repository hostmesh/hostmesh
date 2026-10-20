<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\AuditContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Issue #204, decision 0018: behind a CDN or a reverse proxy, the client
// address comes from X-Forwarded-For, and only when a proxy that the
// operator named sent it.

const PROXY = '10.20.30.40';
const CLIENT = '198.51.100.23';
const STRANGER = '203.0.113.99';

function setTrustedProxies(?string $setting): void
{
    if ($setting === null) {
        putenv('HOSTMESH_TRUSTED_PROXIES');
        unset($_ENV['HOSTMESH_TRUSTED_PROXIES'], $_SERVER['HOSTMESH_TRUSTED_PROXIES']);

        return;
    }

    putenv('HOSTMESH_TRUSTED_PROXIES='.$setting);
    $_ENV['HOSTMESH_TRUSTED_PROXIES'] = $setting;
    $_SERVER['HOSTMESH_TRUSTED_PROXIES'] = $setting;
}

/** A route that answers what the application made of the request, in the `api` group like every API route. */
function clientProbe(): string
{
    Route::middleware('api')->get('/api/v1/client-probe', fn (Request $request, AuditContext $context): array => [
        'ip' => $request->ip(),
        'audit_ip' => $context->ipAddress(),
        'host' => $request->getHost(),
        'secure' => $request->isSecure(),
        'port' => $request->getPort(),
    ])->name('client-probe');

    return 'https://api.hostmesh.test/api/v1/client-probe';
}

/** @param array<string, string> $headers */
function probeFrom(mixed $test, string $address, array $headers = []): array
{
    return $test->withServerVariables(['REMOTE_ADDR' => $address])
        ->withHeaders($headers)
        ->getJson(clientProbe())
        ->assertOk()
        ->json();
}

afterEach(function (): void {
    setTrustedProxies(null);
});

it('trusts no proxy without the setting, whatever a request claims', function (): void {
    $answer = probeFrom($this, PROXY, ['X-Forwarded-For' => CLIENT]);

    expect($answer['ip'])->toBe(PROXY)
        ->and($answer['audit_ip'])->toBe(PROXY);
});

it('takes the client address from X-Forwarded-For of a trusted proxy', function (string $setting): void {
    setTrustedProxies($setting);
    $this->refreshApplication();

    $answer = probeFrom($this, PROXY, ['X-Forwarded-For' => CLIENT]);

    expect($answer['ip'])->toBe(CLIENT)
        ->and($answer['audit_ip'])->toBe(CLIENT);
})->with([
    'the address' => PROXY,
    'its range' => '10.0.0.0/8',
    'among others' => '2001:db8::/32, 192.0.2.10, 10.20.30.0/24',
]);

it('keeps the connecting address of anyone else, whatever headers they send', function (): void {
    setTrustedProxies('10.0.0.0/8');
    $this->refreshApplication();

    $answer = probeFrom($this, STRANGER, [
        'X-Forwarded-For' => CLIENT,
        'Forwarded' => 'for='.CLIENT,
        'X-Real-IP' => CLIENT,
        'CF-Connecting-IP' => CLIENT,
    ]);

    expect($answer['ip'])->toBe(STRANGER)
        ->and($answer['audit_ip'])->toBe(STRANGER);
});

// A proxy appends the address it saw. What a client wrote to the left of it
// is the client's own claim, and is not believed.
it('ignores addresses a client forged ahead of the one its proxy saw', function (): void {
    setTrustedProxies('10.0.0.0/8');
    $this->refreshApplication();

    $answer = probeFrom($this, PROXY, ['X-Forwarded-For' => '192.0.2.1, '.CLIENT]);

    expect($answer['ip'])->toBe(CLIENT);
});

it('walks back through a chain of trusted proxies', function (): void {
    setTrustedProxies('10.0.0.0/8');
    $this->refreshApplication();

    $answer = probeFrom($this, PROXY, ['X-Forwarded-For' => '192.0.2.1, '.CLIENT.', 10.0.0.5']);

    expect($answer['ip'])->toBe(CLIENT);
});

it('reads no other forwarded header, even from a trusted proxy', function (): void {
    setTrustedProxies('10.0.0.0/8');
    $this->refreshApplication();

    $answer = probeFrom($this, PROXY, [
        'Forwarded' => 'for=192.0.2.1;host=evil.example;proto=http',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'http',
        'X-Forwarded-Port' => '8080',
        'X-Forwarded-Prefix' => '/evil',
    ]);

    expect($answer)->toBe([
        'ip' => PROXY,
        'audit_ip' => PROXY,
        'host' => 'api.hostmesh.test',
        'secure' => true,
        'port' => 443,
    ]);
});

// The point of it all: clients behind one CDN no longer share a budget.
it('gives every client behind a trusted proxy a rate limit of its own', function (): void {
    setTrustedProxies('10.0.0.0/8');
    $this->refreshApplication();
    $this->freezeTime();
    $branding = 'https://api.hostmesh.test/api/v1/branding';

    $from = fn (string $client) => $this->withServerVariables(['REMOTE_ADDR' => PROXY])
        ->withHeaders(['X-Forwarded-For' => $client])
        ->getJson($branding);

    foreach (range(1, 300) as $ignored) {
        $from(CLIENT)->assertOk();
    }

    $from(CLIENT)->assertTooManyRequests();
    $from('198.51.100.24')->assertOk();
});

it('stops the application at boot when the setting is malformed', function (): void {
    setTrustedProxies('10.0.0.0/8, proxy.example.com');

    expect(fn () => $this->refreshApplication())
        ->toThrow(InvalidArgumentException::class, 'HOSTMESH_TRUSTED_PROXIES');
});

it('reads the setting from the environment in exactly one place', function (): void {
    $reads = collect(File::files(config_path()))
        ->filter(fn ($file): bool => preg_match('/env\(\s*[\'"]HOSTMESH_TRUSTED_PROXIES/', $file->getContents()) === 1)
        ->map->getFilename()
        ->values()
        ->all();

    expect($reads)->toBe(['hostmesh.php']);
});
