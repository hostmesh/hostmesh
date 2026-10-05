<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\ContentSecurityPolicy;
use App\Support\Hostnames;

// Spec section 28: both static apps ship a strict Content Security Policy
// with connect-src limited to the API host. Nginx serves the header; the
// definition lives in config/hostmesh.php.

const STRICT_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
    ."font-src 'self'; connect-src https://api.hostmesh.test; manifest-src 'self'; "
    ."base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

function nginxExample(): string
{
    return (string) file_get_contents(base_path('../infrastructure/nginx/hostmesh.conf.example'));
}

it('defines the header value for each static app', function (string $app): void {
    expect(app(ContentSecurityPolicy::class)->header($app))->toBe(STRICT_POLICY);
})->with(['portal', 'admin']);

it('limits connect-src to the API origin', function (string $app): void {
    $directives = explode('; ', app(ContentSecurityPolicy::class)->header($app));

    expect($directives)->toContain('connect-src https://api.hostmesh.test');
})->with(['portal', 'admin']);

it('allows nothing inline, evaluated or wildcarded', function (string $app): void {
    expect(app(ContentSecurityPolicy::class)->header($app))
        ->not->toContain('unsafe-inline', 'unsafe-eval', '*', 'http:');
})->with(['portal', 'admin']);

it('keeps the Nginx example in step with the configured policy', function (): void {
    $policy = ContentSecurityPolicy::fromConfig(config('hostmesh.csp'), new Hostnames('example.com'));

    foreach (['portal', 'admin'] as $app) {
        expect(nginxExample())->toContain(
            'add_header Content-Security-Policy "'.$policy->header($app).'" always;'
        );
    }

    expect(substr_count(nginxExample(), 'add_header Content-Security-Policy'))->toBe(2);
});

it('gives the four hostnames a server block in the Nginx example', function (string $host): void {
    expect(nginxExample())->toMatch('/^\s*server_name '.preg_quote($host, '/').';$/m');
})->with(['app.example.com', 'admin.example.com', 'api.example.com', 'agent.example.com']);

it('documents that the agent hostname is never proxied by a CDN', function (): void {
    expect(nginxExample())->toContain('never behind a CDN')
        ->and((string) file_get_contents(base_path('../infrastructure/nginx/README.md')))->toContain('never behind a CDN');
});
