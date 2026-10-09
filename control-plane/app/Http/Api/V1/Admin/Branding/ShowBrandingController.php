<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Queries\GetBranding;
use App\Domain\Branding\BrandingRecord;
use Illuminate\Support\Facades\Gate;

final class ShowBrandingController
{
    /**
     * Show branding
     *
     * The branding of this installation, with the colors that fail WCAG AA and what the current administrator may do.
     * Needs the `branding.view` permission.
     */
    public function __invoke(GetBranding $query): BrandingResource
    {
        Gate::authorize('view', new BrandingRecord);

        return new BrandingResource($query->handle());
    }
}
