<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

use App\Domain\Branding\BrandingRecord;

final readonly class GetBranding
{
    /** The stored branding, or the defaults when nothing is configured. */
    public function handle(): Branding
    {
        return Branding::fromRecord(BrandingRecord::current());
    }
}
