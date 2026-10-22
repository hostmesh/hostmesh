<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\RetentionPolicy;
use Carbon\CarbonImmutable;

/** The retention of the audit log cleared `ip_address` and `user_agent` of a batch of events (spec section 21, issue #239). */
final readonly class AuditAddressesCleared implements Audited
{
    /**
     * @param  int  $count  the events cleared by one batch
     * @param  CarbonImmutable  $cutoff  events older than this were cleared
     */
    public function __construct(
        public int $count,
        public CarbonImmutable $cutoff,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            ActorType::System,
            null,
            AuditAction::AuditAddressesCleared,
            RetentionPolicy::RESOURCE_TYPE,
            RetentionPolicy::RESOURCE_ID,
            ['count' => $this->count, 'cutoff' => $this->cutoff->toIso8601ZuluString()],
            CarbonImmutable::now(),
        );
    }
}
