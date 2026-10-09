<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Branding\BrandingRecord;

/** Writes the branding row directly, for tests that only read it. */
final class StoredBranding
{
    /** @param  array<string, mixed>  $attributes  columns of `branding` */
    public static function with(array $attributes = []): BrandingRecord
    {
        $record = BrandingRecord::current() ?? (new BrandingRecord)->forceFill([
            'id' => BrandingRecord::ID,
            'product_name' => BrandingRecord::DEFAULT_PRODUCT_NAME,
        ]);

        $record->forceFill($attributes)->save();

        return $record;
    }
}
