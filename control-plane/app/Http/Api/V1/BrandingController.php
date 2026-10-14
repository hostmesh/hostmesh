<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Application\Queries\GetBranding;
use App\Domain\Branding\ImageSlot;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class BrandingController
{
    /**
     * Branding
     *
     * The product name, logos, favicon, brand colors and links of this installation. Public and cacheable for five
     * minutes: send `If-None-Match` with the `ETag` of an earlier answer to get `304`. A logo, the favicon, a color
     * or a link that is null is not configured: keep your default.
     *
     * @unauthenticated
     */
    #[Response(200, 'The branding, or the defaults when nothing is configured.')]
    #[Response(429, 'Too many requests from this address: 300 a minute for this and the branding images together. Retry after the seconds in `Retry-After`.', type: 'array{message: string}')]
    public function __invoke(GetBranding $query): JsonResponse
    {
        $branding = $query->handle();

        return new JsonResponse(['data' => [
            'product_name' => $branding->productName,
            'logos' => [
                'light' => BrandingImageUrl::of(ImageSlot::LogoLight, $branding->logoLight),
                'dark' => BrandingImageUrl::of(ImageSlot::LogoDark, $branding->logoDark),
            ],
            'favicon' => BrandingImageUrl::of(ImageSlot::Favicon, $branding->favicon),
            'colors' => [
                'light' => [
                    'primary' => $branding->primaryLight,
                    'primary_foreground' => $branding->primaryForegroundLight,
                ],
                'dark' => [
                    'primary' => $branding->primaryDark,
                    'primary_foreground' => $branding->primaryForegroundDark,
                ],
            ],
            'links' => [
                'support' => $branding->supportUrl,
                'terms' => $branding->termsUrl,
                'privacy' => $branding->privacyUrl,
            ],
        ]]);
    }
}
