<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures\OpenApi;

final class ThrowingRulesController
{
    /**
     * @return array{ok: bool}
     */
    public function __invoke(ThrowingRulesRequest $request): array
    {
        return ['ok' => true];
    }
}
