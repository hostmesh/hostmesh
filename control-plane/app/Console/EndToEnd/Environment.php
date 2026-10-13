<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\EndToEnd;

/**
 * Where the end-to-end commands exist (issue #32). An allowlist: an
 * environment nobody thought of is refused.
 */
final class Environment
{
    /** `e2e`: the stack of compose.e2e.yaml. `testing`: Pest. */
    public const array ALLOWED = ['e2e', 'testing'];

    public static function allows(string $environment): bool
    {
        return in_array($environment, self::ALLOWED, true);
    }
}
