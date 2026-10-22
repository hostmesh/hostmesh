<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

/**
 * The orders the audit log is listed in, as the `sort` parameter names
 * them (decision 0019): a field, with a leading `-` for descending.
 */
enum AuditEventSort: string
{
    case NewestFirst = '-created_at';
    case OldestFirst = 'created_at';

    /** @return 'asc'|'desc' */
    public function direction(): string
    {
        return $this === self::OldestFirst ? 'asc' : 'desc';
    }
}
