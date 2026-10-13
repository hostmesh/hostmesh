<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\EndToEnd;

use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan e2e:seed` (issue #32). Guarded twice: AppServiceProvider
 * registers it only where Environment allows, and it checks again here, so
 * that neither a mistake there nor a direct call can run it elsewhere.
 */
final class SeedCommand extends Command
{
    protected $signature = 'e2e:seed';

    protected $description = 'Create the accounts and branding of the Playwright suite and print their credentials as JSON';

    /** @throws Throwable */
    public function handle(Fixtures $fixtures): int
    {
        $environment = $this->laravel->environment();

        if (! Environment::allows($environment)) {
            $this->components->error("e2e:seed refuses to run in the \"$environment\" environment.");

            return self::FAILURE;
        }

        $this->line(json_encode($fixtures->seed(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
