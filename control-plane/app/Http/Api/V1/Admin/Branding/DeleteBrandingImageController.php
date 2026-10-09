<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Commands\RemoveBrandingImage;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use App\Domain\Identity\Administrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class DeleteBrandingImageController
{
    /**
     * Remove a branding image
     *
     * Empties a slot: `logo-light`, `logo-dark` or `favicon`. The apps then show their built-in default.
     * Answers `200` for a slot that is already empty. Needs the `branding.update` permission.
     */
    public function __invoke(Request $request, string $slot, RemoveBrandingImage $remove): BrandingResource
    {
        // Before the slot is looked at, as in StoreBrandingImageRequest.
        Gate::authorize('update', new BrandingRecord);

        $administrator = $request->user('admin');
        $imageSlot = ImageSlot::tryFrom($slot);

        abort_unless($administrator instanceof Administrator, 401);
        abort_if($imageSlot === null, 404);

        return new BrandingResource($remove->handle($administrator, $imageSlot));
    }
}
