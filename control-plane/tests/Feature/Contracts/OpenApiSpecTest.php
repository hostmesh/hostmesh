<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

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

it('keeps the committed spec identical to the generated one', function (): void {
    $path = committedOpenApiPath();

    expect(is_file($path))->toBeTrue('contracts/api/openapi.json is missing. Run `composer openapi` and commit the result.')
        ->and(generateOpenApi())->toBe(
            (string) file_get_contents($path),
            'contracts/api/openapi.json is stale or was edited by hand. Run `composer openapi` and commit the result.',
        );
});
