<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Application\Queries\GetBrandingImage;
use App\Domain\Branding\ImageSlot;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BrandingImageController
{
    /**
     * Branding image
     *
     * A logo or the favicon, as a PNG, JPEG or WebP file. Use the URLs of `GET /branding`: with their `v` the image
     * is immutable and may be cached for a year. Without `v`, or with the `v` of an earlier version, the current
     * image is sent and may be cached for five minutes. `404` when the slot is empty.
     *
     * @unauthenticated
     */
    #[ApiResponse(200, 'The image.', mediaType: 'image/png', format: 'binary')]
    #[ApiResponse(200, 'The image.', mediaType: 'image/jpeg', format: 'binary')]
    #[ApiResponse(200, 'The image.', mediaType: 'image/webp', format: 'binary')]
    #[ApiResponse(429, 'Too many requests from this address: 300 a minute for this and `GET /branding` together. Retry after the seconds in `Retry-After`.', type: 'array{message: string}')]
    public function __invoke(Request $request, ImageSlot $slot, GetBrandingImage $query): Response
    {
        $file = $query->handle($slot);

        abort_if($file === null, 404);

        $response = new Response($file->contents, 200, [
            // The type found in the bytes at upload, and nothing a browser may guess instead.
            'Content-Type' => $file->mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);

        $response->setEtag($file->version);
        $response->setPublic();

        if ($request->query('v') === $file->version) {
            $response->setMaxAge(31536000);
            $response->setImmutable();
        } else {
            $response->setMaxAge(300);
        }

        $response->isNotModified($request);

        return $response;
    }
}
