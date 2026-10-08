<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Customer;

use App\Domain\Identity\User;
use App\Http\Api\V1\Concerns\ReportsAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    use ReportsAbilities;

    /** @return array{id: string, name: string, email: string, two_factor_enabled: bool, abilities: array{view: bool, update: bool, manage_two_factor: bool}} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'two_factor_enabled' => $this->hasTwoFactor(),
            'abilities' => [
                'view' => $this->allows($request, 'view'),
                'update' => $this->allows($request, 'update'),
                'manage_two_factor' => $this->allows($request, 'manageTwoFactor'),
            ],
        ];
    }
}
