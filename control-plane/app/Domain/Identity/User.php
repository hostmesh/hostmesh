<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\TwoFactor\HasTwoFactor;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person who logs in to the customer app. Authenticated by the
 * `customer` guard only (spec section 28).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property list<string>|null $two_factor_recovery_codes
 */
#[UseFactory(UserFactory::class)]
#[Fillable('name', 'email', 'password')]
#[Hidden('password', 'two_factor_secret', 'two_factor_recovery_codes')]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasTwoFactor;
    use HasUuids;

    /**
     * There is no "remember me": a session end with its lifetime.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower($value));
    }
}
