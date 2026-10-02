<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Application\Queries\GetHealth;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(GetHealth $query): JsonResponse
    {
        return new JsonResponse(['status' => $query->handle()->value]);
    }
}
