<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use JsonException;

/**
 * The audit log in PostgreSQL (decision 0013): one insert on the
 * connection the caller's transaction is open on, with no lock shared
 * between requests.
 */
final readonly class DatabaseAuditLog implements AuditLog
{
    public function __construct(
        protected ConnectionInterface $connection,
    ) {
        //
    }

    /** @throws JsonException */
    public function record(AuditEvent $event): void
    {
        $this->connection->table('audit_events')->insert([
            // No time argument: on its own clock the generator stays
            // monotonic, so two events of one instant keep their order.
            'id' => (string) Str::uuid7(),
            'actor_type' => $event->actorType->value,
            'actor_id' => $event->actorId,
            'action' => $event->action->value,
            'resource' => $event->resource,
            'resource_id' => $event->resourceId,
            // An object also when empty: `{}`, not `[]`.
            'metadata' => json_encode((object) $event->metadata, JSON_THROW_ON_ERROR),
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
            'request_id' => $event->requestId,
            'created_at' => $event->occurredAt->utc()->format('Y-m-d H:i:sP'),
        ]);
    }
}
