<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Application\Queries\GetHealth;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    /**
     * Liveness check
     *
     * Reports that the application booted and can answer. It checks no dependency.
     *
     * @unauthenticated
     */
    #[Response(200, 'The API is up.')]
    public function __invoke(GetHealth $query): JsonResponse
    {
        return new JsonResponse(['status' => $query->handle()]);
    }
}
