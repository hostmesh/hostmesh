<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

use InvalidArgumentException;

/**
 * How long the audit log keeps what (spec section 21, decision 0013):
 * events for `eventMonths`, `ip_address` and `user_agent` for
 * `addressDays`. Both are configurable defaults. The jobs that apply it
 * change at most `batchSize` events per statement and start no new batch
 * after `runSeconds` (issue #238).
 */
final readonly class RetentionPolicy
{
    /**
     * The trigger on `audit_events` refuses to delete a younger event.
     * Not configurable: an operator can shorten retention to it, a bug
     * cannot remove recent history.
     */
    public const int FLOOR_MONTHS = 12;

    /**
     * The retention jobs act on the whole audit log, not on one resource.
     * Their `job_history` rows name it with this type and fixed ID, as
     * the branding's audit events name its only row.
     */
    public const string RESOURCE_TYPE = 'audit_log';

    public const string RESOURCE_ID = '00000000-0000-4000-8000-000000000002';

    public int $eventMonths;

    /**
     * @param  int  $eventMonths  raised to the floor when it is shorter
     * @param  int  $addressDays  at least 1
     * @param  int  $batchSize  events per statement, at least 1
     * @param  int  $runSeconds  after this, a run starts no new batch; 0 is one batch per run
     */
    public function __construct(
        int $eventMonths,
        public int $addressDays,
        public int $batchSize,
        public int $runSeconds,
    ) {
        if ($addressDays < 1) {
            throw new InvalidArgumentException('Addresses must be kept for at least 1 day.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('A batch must hold at least 1 event.');
        }

        if ($runSeconds < 0) {
            throw new InvalidArgumentException('The run time cannot be negative.');
        }

        $this->eventMonths = max(self::FLOOR_MONTHS, $eventMonths);
    }
}
