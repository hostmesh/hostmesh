<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Identity\TwoFactor\HasTwoFactor;
use Carbon\CarbonImmutable;
use Database\Factories\AdministratorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A member of staff who logs in to the admin app. Authenticated by the
 * `admin` guard only (spec section 28).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property list<string>|null $two_factor_recovery_codes
 * @property-read Collection<int, AdministratorRole> $roleAssignments
 */
#[UseFactory(AdministratorFactory::class)]
#[Fillable('name', 'email', 'password')]
#[Hidden('password', 'two_factor_secret', 'two_factor_recovery_codes')]
final class Administrator extends Authenticatable
{
    /** @use HasFactory<AdministratorFactory> */
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

    /**
     * The only question a policy asks about an administrator (decision 0012).
     * The assignments are read once for this instance, so any number of
     * checks in a request costs one query.
     */
    public function hasPermission(Permission $permission): bool
    {
        return array_any($this->roles(), fn (Role $role): bool => $role->grants($permission));
    }

    /** @return list<Role> */
    public function roles(): array
    {
        $roles = [];

        foreach ($this->roleAssignments as $assignment) {
            // A name the code no longer defines grants nothing.
            $role = Role::tryFrom($assignment->role);

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** @return HasMany<AdministratorRole, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(AdministratorRole::class);
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
