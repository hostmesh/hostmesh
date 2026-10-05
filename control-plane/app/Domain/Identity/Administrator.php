<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity;

use Database\Factories\AdministratorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A member of staff who logs in to the admin app. Authenticated by the
 * `admin` guard only (spec section 28).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
#[UseFactory(AdministratorFactory::class)]
#[Fillable('name', 'email', 'password')]
#[Hidden('password')]
final class Administrator extends Authenticatable
{
    /** @use HasFactory<AdministratorFactory> */
    use HasFactory;

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
