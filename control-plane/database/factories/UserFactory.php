<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\User;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
#[UseModel(User::class)]
final class UserFactory extends Factory
{
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
}
