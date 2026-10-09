<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * What an administrator can be allowed to do, as `resource.action`
 * (decision 0012). Permissions are code, not rows: they change by pull
 * request. Only policies ask for them, through
 * Administrator::hasPermission().
 *
 * A permission that ends in `.view` changes nothing, and the `read-only`
 * role holds exactly those.
 */
enum Permission: string
{
    case AdministratorView = 'administrator.view';
    case AdministratorUpdate = 'administrator.update';
    case UserView = 'user.view';
    case UserUpdate = 'user.update';
    case AuditEventView = 'audit-event.view';
    case BrandingView = 'branding.view';
    case BrandingUpdate = 'branding.update';

    public function isReadOnly(): bool
    {
        return str_ends_with($this->value, '.view');
    }
}
