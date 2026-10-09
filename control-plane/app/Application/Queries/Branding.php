<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

use App\Domain\Branding\BrandingRecord;

/**
 * Branding as the application sees it (spec section 28): what the apps,
 * emails and invoices read. The three image properties hold the name of a
 * stored file, or null. A color that is null keeps the token default.
 */
final readonly class Branding
{
    public function __construct(
        public string $productName,
        public ?string $logoLight,
        public ?string $logoDark,
        public ?string $favicon,
        public ?string $primaryLight,
        public ?string $primaryForegroundLight,
        public ?string $primaryDark,
        public ?string $primaryForegroundDark,
        public ?string $supportUrl,
        public ?string $termsUrl,
        public ?string $privacyUrl,
    ) {
        //
    }

    /** What an installation shows before anybody configured anything. */
    public static function defaults(): self
    {
        return new self(BrandingRecord::DEFAULT_PRODUCT_NAME, null, null, null, null, null, null, null, null, null, null);
    }

    public static function fromRecord(?BrandingRecord $record): self
    {
        if ($record === null) {
            return self::defaults();
        }

        return new self(
            $record->product_name,
            $record->logo_light,
            $record->logo_dark,
            $record->favicon,
            $record->primary_light,
            $record->primary_foreground_light,
            $record->primary_dark,
            $record->primary_foreground_dark,
            $record->support_url,
            $record->terms_url,
            $record->privacy_url,
        );
    }
}
