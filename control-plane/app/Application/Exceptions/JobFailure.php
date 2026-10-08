<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use App\Domain\Provisioning\FailureClass;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * How a one-shot job says which failure class of spec section 8 it hit.
 * The message is stored in `job_history`: it must never contain a secret.
 * Anything else a job throws is treated as retryable and reported.
 */
final class JobFailure extends RuntimeException
{
    private function __construct(
        public readonly FailureClass $failureClass,
        string $message,
        public readonly ?int $recheckAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Service timeout, network error: try again later, up to the attempt limit. */
    public static function retryable(string $message, ?Throwable $previous = null): self
    {
        return new self(FailureClass::Retryable, $message, null, $previous);
    }

    /**
     * Blocked on an external condition. Not a failure, and it never counts
     * toward the attempt limit.
     *
     * @param  int|null  $recheckAfter  seconds until the next look; null for the configured default
     */
    public static function waiting(string $message, ?int $recheckAfter = null): self
    {
        if ($recheckAfter !== null && $recheckAfter < 1) {
            throw new InvalidArgumentException('A recheck must be at least 1 second away.');
        }

        return new self(FailureClass::Waiting, $message, $recheckAfter);
    }

    /** Invalid input, quota exceeded: trying again cannot help. */
    public static function terminal(string $message, ?Throwable $previous = null): self
    {
        return new self(FailureClass::Terminal, $message, null, $previous);
    }
}
