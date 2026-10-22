<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Infrastructure\OpenApi\IncompleteOpenApiDocument;
use Dedoc\Scramble\OpenApiVisitor\SchemaEnforceVisitor;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Fixtures\OpenApi\AardvarkGroupController;
use Tests\Fixtures\OpenApi\ThrowingRulesController;
use Tests\Fixtures\OpenApi\UnknownTypeController;
use Tests\Fixtures\OpenApi\WeightedGroupController;
use Tests\Fixtures\OpenApi\ZebraGroupController;

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

    // Scramble checks a component schema once per PHP process. `composer
    // openapi` is one process and one generation; here every test generates,
    // and each must check every component again.
    (new ReflectionProperty(SchemaEnforceVisitor::class, 'handledReferences'))->setValue(null, []);

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

// Issue #160: Scramble adds error responses and tags in the order it meets
// them, so a new route could reorder them in the committed document.
it('lists the shared responses in alphabetical order', function (): void {
    $names = array_keys(generateOpenApiDocument()['components']['responses']);
    $sorted = $names;
    sort($sorted, SORT_STRING);

    expect($names)->not->toBeEmpty()
        ->and($names)->toBe($sorted);
});

it('lists tags by weight, then by name, whatever the order of the routes', function (): void {
    Route::get('/api/v1/zebra-group', ZebraGroupController::class)->name('zebra-group');
    Route::get('/api/v1/aardvark-group', AardvarkGroupController::class)->name('aardvark-group');
    Route::get('/api/v1/weighted-group', WeightedGroupController::class)->name('weighted-group');

    $tags = array_column(generateOpenApiDocument()['tags'] ?? [], 'name');

    expect($tags)->toBe(['Middle', 'Aardvark', 'Zebra']);
});

// A route bound to a domain would get a server of its own, built with the
// scheme of APP_URL. Every route is on the API host, under the one server.
it('gives no path or operation a server of its own', function (): void {
    Route::domain('{tenant}.example.com')->get('/api/v1/domain-bound', ZebraGroupController::class)->name('domain-bound');

    $before = generateOpenApi();
    $path = json_decode($before, true, flags: JSON_THROW_ON_ERROR)['paths']['/domain-bound'];

    config(['app.url' => 'http://elsewhere.example']);
    URL::forceRootUrl('http://elsewhere.example');

    expect($path)->not->toHaveKey('servers')
        ->and($path['get'])->not->toHaveKey('servers')
        ->and(generateOpenApi())->toBe($before);
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

it('documents the public branding endpoint and its response', function (): void {
    $operation = generateOpenApiDocument()['paths']['/branding']['get'];
    $data = $operation['responses']['200']['content']['application/json']['schema']['properties']['data'];

    expect($operation['summary'] ?? null)->toBe('Branding')
        ->and($operation['security'])->toBe([])
        ->and(array_keys($data['properties']))->toBe(['product_name', 'logos', 'favicon', 'colors', 'links'])
        ->and(array_keys($data['properties']['colors']['properties']['dark']['properties']))->toBe(['primary', 'primary_foreground']);
});

it('documents saving branding', function (): void {
    $operation = generateOpenApiDocument()['paths']['/admin/branding']['put'];

    expect($operation['summary'] ?? null)->toBe('Update branding')
        ->and($operation['requestBody']['content'])->toHaveKey('application/json')
        ->and(array_keys($operation['responses']))->toContain(200, 403, 422);
});

it('documents the branding image endpoints', function (): void {
    $paths = generateOpenApiDocument()['paths'];
    $upload = $paths['/admin/branding/images/{slot}']['post'];

    expect($upload['summary'] ?? null)->toBe('Upload a branding image')
        ->and($upload['requestBody']['content'])->toHaveKey('multipart/form-data')
        ->and($paths['/admin/branding/images/{slot}']['delete']['summary'] ?? null)->toBe('Remove a branding image')
        ->and($paths['/branding/images/{slot}']['get']['summary'] ?? null)->toBe('Branding image')
        ->and($paths['/branding/images/{slot}']['get']['security'])->toBe([]);
});

it('documents a branding image as the bytes of an image, not as JSON', function (): void {
    $paths = generateOpenApiDocument()['paths'];
    $content = $paths['/branding/images/{slot}']['get']['responses']['200']['content'];

    expect(array_keys($content))->toBe(['image/png', 'image/jpeg', 'image/webp']);

    foreach ($content as $mediaType) {
        expect($mediaType['schema'])->toBe(['type' => 'string', 'format' => 'binary']);
    }

    // The upload answers 404 for an unknown slot, with the usual error body.
    $notFound = $paths['/admin/branding/images/{slot}']['post']['responses']['404']['content']['application/json']['schema'];

    expect(array_keys($notFound['properties']))->toBe(['message']);
});

it('documents the rate limit of both public branding routes', function (string $path): void {
    $response = generateOpenApiDocument()['paths'][$path]['get']['responses']['429'] ?? null;

    expect($response)->not->toBeNull("GET $path does not document 429")
        ->and(array_keys($response['content']['application/json']['schema']['properties']))->toBe(['message']);
})->with(['/branding', '/branding/images/{slot}']);

// Issue #212: the password and the code go in the body. Scramble documents
// the parameters of a DELETE as a query string, so this is no DELETE.
it('documents disabling two-factor with the password and the code in the body', function (string $realm): void {
    $paths = generateOpenApiDocument()['paths'];
    $operation = $paths["/$realm/auth/two-factor/disable"]['post'] ?? null;

    expect($operation)->not->toBeNull("POST /$realm/auth/two-factor/disable is missing")
        ->and($operation['parameters'] ?? [])->toBe([])
        ->and($operation['requestBody']['content']['application/json']['schema'])->toBe(['$ref' => '#/components/schemas/PasswordAndCodeRequest'])
        ->and($paths["/$realm/auth/two-factor"])->not->toHaveKey('delete');
})->with(['admin', 'customer']);

// Issue #202: a route behind `throttle` documents 429, with the usual error
// body and the Retry-After header, without an attribute on its controller.

/** @return list<array{string, string}> the path and method in the document of every throttled operation */
function throttledOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $throttled = collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && ($middleware === 'throttle' || str_starts_with($middleware, 'throttle:')));

        if (! $throttled || ! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $operations[] = [mb_substr($route->uri(), mb_strlen('api/v1')), mb_strtolower($method)];
        }
    }

    return $operations;
}

it('documents 429 and Retry-After on every throttled route', function (): void {
    $paths = generateOpenApiDocument()['paths'];
    $operations = throttledOperations();

    // Login, four two-factor operations and the password change in each
    // realm, and the two public branding routes.
    expect($operations)->toHaveCount(14);

    foreach ($operations as [$path, $method]) {
        $response = $paths[$path][$method]['responses']['429'] ?? null;

        expect($response)->not->toBeNull(mb_strtoupper($method)." $path does not document 429")
            ->and(array_keys($response['content']['application/json']['schema']['properties']))->toBe(['message'])
            ->and($response['headers']['Retry-After']['required'] ?? null)->toBeTrue(mb_strtoupper($method)." $path does not document Retry-After")
            ->and($response['headers']['Retry-After']['schema'])->toBe(['type' => 'integer']);
    }
});

it('documents no 429 on a route without a throttle', function (): void {
    $paths = generateOpenApiDocument()['paths'];

    expect($paths['/health']['get']['responses'])->not->toHaveKey('429')
        ->and($paths['/admin/auth/user']['get']['responses'])->not->toHaveKey('429');
});

it('keeps the 429 that a controller documents itself', function (): void {
    $response = generateOpenApiDocument()['paths']['/branding']['get']['responses']['429'];

    expect($response['description'])->toStartWith('Too many requests from this address: 300 a minute');
});

// Issue #62: the operation ID is the route name. An unnamed route gets an ID
// that Scramble derives from the controller's namespace and class, and that
// ID changes when another controller of the same name appears.
it('gives every operation its route name as the operation ID', function (): void {
    $paths = generateOpenApiDocument()['paths'];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = substr($route->uri(), strlen('api/v1'));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $where = "{$method} {$path}";
            $operation = $paths[$path][strtolower($method)] ?? null;

            expect($route->getName())->not->toBeNull("{$where} has no route name. Name it in routes/api.php: the name is its operation ID.")
                ->and($operation)->not->toBeNull("{$where} is missing from the document.")
                ->and($operation['operationId'] ?? null)->toBe($route->getName(), "{$where} has an operation ID that is not its route name.");

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(20);
});

it('gives no two operations the same ID', function (): void {
    $ids = [];

    foreach (generateOpenApiDocument()['paths'] as $operations) {
        foreach ($operations as $operation) {
            $ids[] = $operation['operationId'];
        }
    }

    expect($ids)->toBe(array_values(array_unique($ids)));
});

// Issue #63: a document that could not be built in full is not written.
// The settings are in AppServiceProvider, so `composer openapi` and these
// tests cannot disagree.
it('fails when the request documentation of an operation cannot be built', function (): void {
    Route::post('/api/v1/throwing-rules', ThrowingRulesController::class)->name('throwing-rules');

    expect(fn (): string => generateOpenApi())->toThrow(IncompleteOpenApiDocument::class, 'ThrowingRulesRequest::rules()');
});

it('fails when a type cannot be inferred', function (): void {
    Route::get('/api/v1/unknown-type', UnknownTypeController::class)->name('unknown-type');

    expect(fn (): string => generateOpenApi())->toThrow(IncompleteOpenApiDocument::class, 'Schema `UnknownType` is not allowed');
});

it('names every problem and writes no document', function (): void {
    Route::post('/api/v1/throwing-rules', ThrowingRulesController::class)->name('throwing-rules');
    Route::get('/api/v1/unknown-type', UnknownTypeController::class)->name('unknown-type');
    $path = (string) tempnam(sys_get_temp_dir(), 'openapi-');

    try {
        Artisan::call('scramble:export', ['--path' => $path]);

        $message = 'no exception';
    } catch (IncompleteOpenApiDocument $exception) {
        $message = $exception->getMessage();
    } finally {
        $written = (string) file_get_contents($path);
        @unlink($path);
    }

    expect($message)->toContain('ThrowingRulesRequest', 'UnknownType', 'unknown-type/get', 'When generation fails')
        ->and($written)->toBe('');
});

// Found by the check above: the document said `string`.
it('documents the metadata of an audit event as an object of scalar values', function (): void {
    $schema = generateOpenApiDocument()['components']['schemas']['AuditEventResource']['properties']['metadata'];

    expect($schema)->toBe([
        'type' => 'object',
        'additionalProperties' => ['type' => ['boolean', 'integer', 'string', 'null']],
    ]);
});

/**
 * The GET operations that answer a collection: a `data` array.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, array<string, mixed>>
 */
function listOperations(array $document): array
{
    $lists = [];

    foreach ($document['paths'] as $path => $operations) {
        $schema = $operations['get']['responses']['200']['content']['application/json']['schema'] ?? null;

        if (($schema['properties']['data']['type'] ?? null) === 'array') {
            $lists[$path] = $operations['get'];
        }
    }

    return $lists;
}

// Issue #143, decision 0019: every list is cursor paginated with the same
// parameters and the same meta, filtered by flat parameters and sorted by
// `sort=<field>` or `sort=-<field>` from the values the endpoint offers.
it('lists every collection the same way', function (): void {
    $document = json_decode((string) file_get_contents(committedOpenApiPath()), true, flags: JSON_THROW_ON_ERROR);
    $lists = listOperations($document);

    expect($lists)->not->toBeEmpty();

    foreach ($lists as $path => $operation) {
        $parameters = collect($operation['parameters'] ?? [])->keyBy('name');
        $body = $operation['responses']['200']['content']['application/json']['schema'];

        expect($parameters->get('per_page')['schema'] ?? null)->toBe(['type' => 'integer', 'minimum' => 1, 'maximum' => 100], "$path: per_page")
            ->and($parameters->get('cursor')['schema']['type'] ?? null)->toBe('string', "$path: cursor")
            ->and($parameters->has('page'))->toBeFalse("$path: offset pages")
            ->and($body['required'])->toBe(['data', 'links', 'meta'], "$path: body")
            ->and($body['properties']['meta']['required'])->toBe(['path', 'per_page', 'next_cursor', 'prev_cursor'], "$path: meta");

        if ($parameters->has('sort')) {
            $reference = $parameters->get('sort')['schema']['$ref'] ?? '';
            $values = $document['components']['schemas'][Str::afterLast($reference, '/')]['enum'] ?? [];

            expect($values)->not->toBeEmpty("$path: sort values")
                ->each->toMatch('/^-?[a-z][a-z_]*$/');
        }
    }
});
