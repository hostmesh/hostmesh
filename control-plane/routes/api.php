<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Served under /api/v1 (see bootstrap.app).

use App\Http\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
