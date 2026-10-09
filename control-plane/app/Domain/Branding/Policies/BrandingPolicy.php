<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding\Policies;

use App\Domain\Authorization\Permission;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/**
 * Who may read and edit branding in the admin realm (decision 0012, spec
 * section 28). The public endpoint asks nobody. There is no method for
 * creating or deleting the record, so the Gate denies both.
 */
final class BrandingPolicy
{
    public function view(Administrator|User $actor, BrandingRecord $branding): bool
    {
        return $actor instanceof Administrator && $actor->hasPermission(Permission::BrandingView);
    }

    public function update(Administrator|User $actor, BrandingRecord $branding): bool
    {
        return $actor instanceof Administrator && $actor->hasPermission(Permission::BrandingUpdate);
    }
}
