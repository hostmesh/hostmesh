<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

use App\Application\Commands\StoreBrandingImage;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use Illuminate\Contracts\Filesystem\Factory;

final readonly class GetBrandingImage
{
    public function __construct(
        protected Factory $filesystems,
    ) {
        //
    }

    /**
     * The image of the slot, or null when the slot is empty or its file
     * is gone. The path comes from the record: nothing a client sends
     * reaches the disk.
     */
    public function handle(ImageSlot $slot): ?BrandingImageFile
    {
        $name = BrandingRecord::current()?->getAttribute($slot->column());

        if (! is_string($name)) {
            return null;
        }

        $mimeType = array_search(pathinfo($name, PATHINFO_EXTENSION), ImageSlot::EXTENSIONS, true);
        $disk = $this->filesystems->disk(StoreBrandingImage::DISK);

        if ($mimeType === false || ! $disk->exists($slot->path($name))) {
            return null;
        }

        $contents = $disk->get($slot->path($name));

        if ($contents === null) {
            return null;
        }

        return new BrandingImageFile($contents, $mimeType, pathinfo($name, PATHINFO_FILENAME));
    }
}
