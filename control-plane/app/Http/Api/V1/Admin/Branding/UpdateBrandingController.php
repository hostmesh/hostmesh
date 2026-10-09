<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Commands\UpdateBranding;
use App\Domain\Identity\Administrator;
use Throwable;

final class UpdateBrandingController
{
    /**
     * Update branding
     *
     * Replaces the product name, the brand colors and the links. Send every field: null clears a color or a link.
     * A color is `#rrggbb`; a link is an `https` URL. A color that fails WCAG AA (4.5:1) against the default
     * surfaces is saved all the same and listed in `warnings`. Logos and the favicon have endpoints of their own.
     * Needs the `branding.update` permission.
     *
     * @throws Throwable
     */
    public function __invoke(UpdateBrandingRequest $request, UpdateBranding $update): BrandingResource
    {
        $administrator = $request->user('admin');

        abort_unless($administrator instanceof Administrator, 401);

        return new BrandingResource($update->handle($administrator, $request->changes()));
    }
}
