<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures\OpenApi;

final class UnknownTypeController
{
    /**
     * Scramble cannot tell what a cast to object holds.
     *
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return ['thing' => (object) ['a' => 1]];
    }
}
