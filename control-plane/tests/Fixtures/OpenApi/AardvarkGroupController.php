<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures\OpenApi;

use Dedoc\Scramble\Attributes\Group;

#[Group('Aardvark')]
final class AardvarkGroupController
{
    /** @return array{ok: bool} */
    public function __invoke(): array
    {
        return ['ok' => true];
    }
}
