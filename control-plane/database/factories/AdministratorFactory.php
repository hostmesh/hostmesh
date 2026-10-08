<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Database\Factories\Concerns\TwoFactorStates;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Administrator> */
#[UseModel(Administrator::class)]
final class AdministratorFactory extends Factory
{
    use TwoFactorStates;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Hashed by the model's cast. A test password, never a real one.
            'password' => 'correct-password',
        ];
    }

    /** An administrator who holds the given roles (decision 0012). */
    public function withRoles(Role ...$roles): static
    {
        return $this->afterCreating(function (Administrator $administrator) use ($roles): void {
            foreach ($roles as $role) {
                $administrator->roleAssignments()->create(['role' => $role->value]);
            }

            $administrator->unsetRelation('roleAssignments');
        });
    }
}
