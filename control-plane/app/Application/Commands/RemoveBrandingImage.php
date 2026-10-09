<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\BrandingImageRemoved;
use App\Application\Queries\Branding;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory;
use Throwable;

final readonly class RemoveBrandingImage
{
    public function __construct(
        protected Dispatcher $events,
        protected Factory $filesystems,
    ) {
        //
    }

    /**
     * Empties the slot, audits it (decision 0013) and deletes the file
     * once the change is committed. An empty slot changes and records
     * nothing. Whether $actor may do this is the caller's policy check.
     *
     * @param  Administrator|null  $actor  who made the change; null when the system did
     *
     * @throws Throwable
     */
    public function handle(?Administrator $actor, ImageSlot $slot): Branding
    {
        $previous = null;

        $branding = (new BrandingRecord)->getConnection()->transaction(function () use ($actor, $slot, &$previous): Branding {
            $record = BrandingRecord::lockedForUpdate();
            $stored = $record->getAttribute($slot->column());
            $previous = is_string($stored) ? $stored : null;

            if ($previous !== null) {
                $record->setAttribute($slot->column(), null);
                $record->save();

                $this->events->dispatch(new BrandingImageRemoved($actor, $slot));
            }

            return Branding::fromRecord($record);
        });

        if ($previous !== null) {
            $this->filesystems->disk(StoreBrandingImage::DISK)->delete($slot->path($previous));
        }

        return $branding;
    }
}
