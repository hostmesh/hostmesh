<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * The failure classes of spec section 8. The value is stored in
 * `job_history.failure_class`: never rename one.
 */
enum FailureClass: string
{
    /** Service timeout, network error: backs off and tries again, up to the attempt limit. */
    case Retryable = 'retryable';

    /** Blocked on an external condition. Rechecked later; never counts toward the attempt limit. */
    case Waiting = 'waiting';

    /** Invalid input, quota exceeded: fails at once. */
    case Terminal = 'terminal';
}
