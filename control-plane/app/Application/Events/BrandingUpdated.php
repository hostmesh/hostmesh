<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use Carbon\CarbonImmutable;

final readonly class BrandingUpdated implements Audited
{
    /**
     * @param  Administrator|null  $actor  who made the change; null when the system did
     * @param  list<string>  $fields  the columns of `branding` that changed, sorted
     */
    public function __construct(
        public ?Administrator $actor,
        public array $fields,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            $this->actor === null ? ActorType::System : ActorType::Administrator,
            $this->actor?->id,
            AuditAction::BrandingUpdated,
            'branding',
            BrandingRecord::ID,
            // Which fields, never their values.
            ['fields' => implode(',', $this->fields)],
            CarbonImmutable::now(),
        );
    }
}
