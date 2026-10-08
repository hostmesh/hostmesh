<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditLog;
use RuntimeException;
use Throwable;

/**
 * The fake of decision 0013. Bind it with
 * `$this->app->instance(AuditLog::class, new InMemoryAuditLog)`.
 */
final class InMemoryAuditLog implements AuditLog
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function __construct(
        protected readonly ?Throwable $failure = null,
    ) {
        //
    }

    /** A log that cannot be written, as when the database refuses the insert. */
    public static function failing(): self
    {
        return new self(new RuntimeException('The audit log cannot be written.'));
    }

    public function record(AuditEvent $event): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->events[] = $event;
    }
}
