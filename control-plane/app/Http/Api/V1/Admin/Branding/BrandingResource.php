<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Branding;

use App\Application\Queries\Branding;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ContrastWarning;
use App\Domain\Branding\ImageSlot;
use App\Http\Api\V1\BrandingImageUrl;
use App\Http\Api\V1\Concerns\ReportsAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Branding $resource */
final class BrandingResource extends JsonResource
{
    use ReportsAbilities;

    /**
     * The shape of the public endpoint, plus `warnings` and `abilities`.
     * A warning names a pair of colors below WCAG AA (4.5:1): `color` on
     * `against`, in `mode`. It never blocks a save.
     *
     * @return array{product_name: string, logos: array{light: string|null, dark: string|null}, favicon: string|null, colors: array{light: array{primary: string|null, primary_foreground: string|null}, dark: array{primary: string|null, primary_foreground: string|null}}, links: array{support: string|null, terms: string|null, privacy: string|null}, warnings: list<array{mode: string, color: string, against: string, ratio: float, required: float}>, abilities: array{view: bool, update: bool}}
     */
    public function toArray(Request $request): array
    {
        $branding = $this->resource;
        // The policy is registered for the record, not for the read model.
        $record = new BrandingRecord;

        return [
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
            /** @var list<array{mode: string, color: string, against: string, ratio: float, required: float}> */
            'warnings' => array_map(
                fn (ContrastWarning $warning): array => $warning->toArray(),
                $branding->contrastWarnings(),
            ),
            'abilities' => [
                'view' => $this->allows($request, 'view', $record),
                'update' => $this->allows($request, 'update', $record),
            ],
        ];
    }
}
