<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

enum HealthStatus: string
{
    case Ok = 'ok';
}
