<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One role held by one administrator (decision 0012). `role` is the value
 * of an App\Domain\Authorization\Role case. Read it through
 * Administrator::roles(), which drops a name the code no longer defines.
 *
 * @property string $administrator_id
 * @property string $role
 */
#[Fillable('role')]
final class AdministratorRole extends Model
{
    public $incrementing = false;

    public $timestamps = false;
}
