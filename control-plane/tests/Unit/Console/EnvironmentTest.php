<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\EndToEnd\Environment;

it('allows the end-to-end and the test environment', function (string $environment): void {
    expect(Environment::allows($environment))->toBeTrue();
})->with(['e2e', 'testing']);

it('refuses every other environment', function (string $environment): void {
    expect(Environment::allows($environment))->toBeFalse();
})->with(['production', 'prod', 'local', 'staging', 'E2E', 'e2e ', '']);
