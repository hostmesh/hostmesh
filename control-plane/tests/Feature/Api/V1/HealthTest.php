<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('reports that the API is up, without authentication', function (): void {
    getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('does not serve the framework health page', function (): void {
    get('/up')->assertNotFound();
});

it('serves no web UI', function (): void {
    get('/')->assertNotFound();
});
