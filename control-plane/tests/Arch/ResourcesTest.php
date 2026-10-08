<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Api\V1\Concerns\ReportsAbilities;
use Illuminate\Http\Resources\Json\JsonResource;

// Decision 0012: every API Resource carries `abilities`.
// tests/Feature/Api/V1/AbilitiesTest.php checks the values.

arch('every API Resource reports abilities')
    ->expect('App\Http\Api\V1')
    ->classes()
    ->extending(JsonResource::class)
    ->toUseTrait(ReportsAbilities::class);

arch('controllers ask the Gate and never inspect roles or permissions')
    ->expect(['App\Domain\Authorization\Role', 'App\Domain\Authorization\Permission'])
    ->not->toBeUsedIn('App\Http');
