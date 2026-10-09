<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Branding;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored branding (spec section 28): one row with a fixed ID. Read it
 * through App\Application\Queries\GetBranding and change it through the
 * commands, which audit every change.
 *
 * @property string $id
 * @property string $product_name
 * @property string|null $logo_light
 * @property string|null $logo_dark
 * @property string|null $favicon
 * @property string|null $primary_light
 * @property string|null $primary_foreground_light
 * @property string|null $primary_dark
 * @property string|null $primary_foreground_dark
 * @property string|null $support_url
 * @property string|null $terms_url
 * @property string|null $privacy_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class BrandingRecord extends Model
{
    /** The only row. Also, the `resource_id` of its audit events. */
    public const string ID = '00000000-0000-4000-8000-000000000001';

    public const string DEFAULT_PRODUCT_NAME = 'Hostmesh';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'branding';

    /** Null until the first change. */
    public static function current(): ?self
    {
        return self::query()->find(self::ID);
    }

    /**
     * The row under a row lock, created with the defaults when it does not
     * exist yet. Call it inside the transaction that changes the row: two
     * administrators saving at once then take turns.
     */
    public static function lockedForUpdate(): self
    {
        $now = now();

        self::query()->insertOrIgnore([
            'id' => self::ID,
            'product_name' => self::DEFAULT_PRODUCT_NAME,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return self::query()->lockForUpdate()->findOrFail(self::ID);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
