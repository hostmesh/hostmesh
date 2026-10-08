<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use Carbon\CarbonImmutable;

/** What ListAuditEvents narrows the log by. Null means: do not narrow. */
final readonly class AuditEventFilter
{
    public function __construct(
        public ?ActorType $actorType = null,
        public ?string $actorId = null,
        public ?string $resource = null,
        public ?string $resourceId = null,
        public ?AuditAction $action = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {
        //
    }
}
