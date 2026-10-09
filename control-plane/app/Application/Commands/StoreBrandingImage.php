<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\BrandingImageReplaced;
use App\Application\Exceptions\InvalidBrandingImage;
use App\Application\Queries\Branding;
use App\Application\Services\ImageInspector;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Branding\ImageSlot;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory;
use Throwable;

final readonly class StoreBrandingImage
{
    /** The Laravel filesystem disk that holds the images (config/filesystems.php). */
    public const string DISK = 'branding';

    public function __construct(
        protected Dispatcher $events,
        protected Factory $filesystems,
        protected ImageInspector $inspector,
    ) {
        //
    }

    /**
     * Puts the file at $path into the slot and audits it (decision 0013).
     * The stored name is the start of the content's SHA-256 and the
     * extension of the type found in the bytes: nothing of it comes from
     * the client. The same image again changes and records nothing.
     * Whether $actor may do this is the caller's policy check.
     *
     * @param  Administrator|null  $actor  who made the change; null when the system did
     * @param  string  $path  a local file, for example the temporary file of an upload
     *
     * @throws InvalidBrandingImage when the bytes are not an image the slot accepts
     * @throws Throwable
     */
    public function handle(?Administrator $actor, ImageSlot $slot, string $path): Branding
    {
        $image = $this->inspector->inspect($path) ?? throw new InvalidBrandingImage(InvalidBrandingImage::NOT_AN_IMAGE);
        $violation = $slot->violation($image);

        if ($violation !== null) {
            throw new InvalidBrandingImage($violation);
        }

        $contents = (string) file_get_contents($path);
        $name = substr(hash('sha256', $contents), 0, 16).'.'.ImageSlot::EXTENSIONS[$image->mimeType];

        $disk = $this->filesystems->disk(self::DISK);
        // False when this very image is already there: then a failure below must not delete it.
        $written = ! $disk->exists($slot->path($name));

        if ($written) {
            $disk->put($slot->path($name), $contents);
        }

        $previous = null;

        try {
            $branding = (new BrandingRecord)->getConnection()->transaction(function () use ($actor, $slot, $name, &$previous): Branding {
                $record = BrandingRecord::lockedForUpdate();
                $stored = $record->getAttribute($slot->column());
                $previous = is_string($stored) ? $stored : null;

                if ($previous !== $name) {
                    $record->setAttribute($slot->column(), $name);
                    $record->save();

                    $this->events->dispatch(new BrandingImageReplaced($actor, $slot));
                }

                return Branding::fromRecord($record);
            });
        } catch (Throwable $exception) {
            if ($written) {
                $disk->delete($slot->path($name));
            }

            throw $exception;
        }

        // After the commit: the row no longer names the old file.
        if ($previous !== null && $previous !== $name) {
            $disk->delete($slot->path($previous));
        }

        return $branding;
    }
}
