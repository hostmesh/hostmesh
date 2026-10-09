<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\BrandingUpdated;
use App\Application\Queries\Branding;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final readonly class UpdateBranding
{
    public function __construct(
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Replaces the name, colors and links of the branding record (spec
     * section 28) and audits the change in the same transaction (decision
     * 0013). A save that changes nothing writes nothing. Whether $actor may
     * do this is the caller's policy check, not this command's.
     *
     * @param  Administrator|null  $actor  who made the change; null when the system did
     *
     * @throws Throwable
     */
    public function handle(?Administrator $actor, BrandingChanges $changes): Branding
    {
        // Before the transaction: a color that is not one stops here.
        $attributes = $changes->attributes();

        return (new BrandingRecord)->getConnection()->transaction(function () use ($actor, $attributes): Branding {
            $record = BrandingRecord::lockedForUpdate();
            $record->forceFill($attributes);

            $fields = array_keys($record->getDirty());

            if ($fields !== []) {
                sort($fields);
                $record->save();

                $this->events->dispatch(new BrandingUpdated($actor, $fields));
            }

            return Branding::fromRecord($record);
        });
    }
}
