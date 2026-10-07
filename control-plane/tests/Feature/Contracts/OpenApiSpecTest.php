<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\get;

// Spec sections 28 and 37: the OpenAPI document is generated from the code
// and committed. Regenerate it with `composer openapi`; never edit it.

function committedOpenApiPath(): string
{
    return base_path('../contracts/api/openapi.json');
}

function generateOpenApi(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'openapi-');

    try {
        expect(Artisan::call('scramble:export', ['--path' => $path]))->toBe(0);

        return (string) file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

/**
 * @return array<string, mixed>
 *
 * @throws JsonException
 */
function generateOpenApiDocument(): array
{
    return json_decode(generateOpenApi(), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the committed spec identical to the generated one', function (): void {
    $path = committedOpenApiPath();

    expect(is_file($path))->toBeTrue('contracts/api/openapi.json is missing. Run `composer openapi` and commit the result.')
        ->and(generateOpenApi())->toBe(
            (string) file_get_contents($path),
            'contracts/api/openapi.json is stale or was edited by hand. Run `composer openapi` and commit the result.',
        );
});

it('generates the same document on every machine', function (): void {
    $before = generateOpenApi();

    config(['app.url' => 'https://elsewhere.example', 'app.name' => 'Elsewhere']);
    URL::forceRootUrl('https://elsewhere.example');

    expect(generateOpenApi())->toBe($before)
        ->and(generateOpenApiDocument()['servers'])->toBe([['url' => '/api/v1']]);
});

it('keeps the relative server URL when the API path uses the include and exclude form', function (): void {
    config(['scramble.api_path' => ['include' => 'api/v1', 'exclude' => ['api/v1/internal']]]);
    Scramble::configure()->useConfig(config('scramble'));

    expect(generateOpenApiDocument()['servers'])->toBe([['url' => '/api/v1']]);
});

it('lists paths in alphabetical order, whatever the order of the routes', function (): void {
    Route::get('/api/v1/zebra', fn (): array => ['ok' => true]);
    Route::get('/api/v1/aardvark', fn (): array => ['ok' => true]);

    // Asserts the order, not the list: a new endpoint must not fail this test.
    $paths = array_keys(generateOpenApiDocument()['paths']);
    $sorted = $paths;
    sort($sorted, SORT_STRING);

    expect($paths)->toBe($sorted)
        ->and(array_values(array_intersect($paths, ['/zebra', '/aardvark'])))->toBe(['/aardvark', '/zebra']);
});

it('serves no API documentation', function (string $uri): void {
    get($uri)->assertNotFound();
})->with(['/docs/api', '/docs/api.json', '/_scramble/dev-tools/devtools.js']);

it('documents login as needing no session and keeps docblock tags out of the description', function (string $path): void {
    $operation = generateOpenApiDocument()['paths'][$path]['post'];

    expect($operation['security'] ?? null)->toBe([])
        ->and($operation['description'])->not->toContain('@unauthenticated');
})->with(['/admin/auth/login', '/customer/auth/login']);

it('documents the health endpoint and its response', function (): void {
    $document = generateOpenApiDocument();
    $operation = $document['paths']['/health']['get'];

    expect($operation['summary'] ?? null)->toBe('Liveness check')
        ->and($operation['description'])->toBe('Reports that the application booted and can answer. It checks no dependency.')
        ->and($operation['security'])->toBe([])
        ->and($operation['responses']['200']['description'])->toBe('The API is up.')
        ->and($operation['responses']['200']['content']['application/json']['schema'])->toBe([
            'type' => 'object',
            'properties' => ['status' => ['$ref' => '#/components/schemas/HealthStatus']],
            'required' => ['status'],
        ])
        ->and($document['components']['schemas']['HealthStatus'])->toMatchArray([
            'type' => 'string',
            'enum' => ['ok'],
        ]);
});
