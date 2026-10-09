<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Commands\StoreBrandingImage;
use App\Domain\Identity\Administrator;

final class StoreBrandingImageController
{
    /**
     * Upload a branding image
     *
     * Replaces the image of a slot: `logo-light`, `logo-dark` or `favicon`. Send the file as `file` in a multipart
     * form. A logo is a PNG, JPEG or WebP file of at most 1024 KB and 2000 by 2000 pixels. The favicon is a square
     * PNG file of at most 256 KB and 512 by 512 pixels. SVG is not accepted. The type is read from the file itself;
     * its name is ignored. Needs the `branding.update` permission.
     */
    public function __invoke(StoreBrandingImageRequest $request, StoreBrandingImage $store): BrandingResource
    {
        $administrator = $request->user('admin');

        abort_unless($administrator instanceof Administrator, 401);

        return new BrandingResource($store->handle($administrator, $request->slot(), $request->uploadedPath()));
    }
}
