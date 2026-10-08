<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

use InvalidArgumentException;

/**
 * How a retryable failure is tried again and how long a waiting one rests
 * (spec section 8). Delays are in seconds. It holds no state and draws no
 * random number: the caller passes one in.
 */
final readonly class RetryPolicy
{
    /**
     * @param  int  $baseDelay  delay after the first failed attempt
     * @param  int  $maxDelay  no delay is longer than this
     * @param  int  $maxAttempts  after this many failed attempts the job fails
     * @param  float  $jitter  share of a delay that chance may take away, 0 to 1
     * @param  int  $recheckDelay  rest before a waiting job looks again
     */
    public function __construct(
        public int $baseDelay,
        public int $maxDelay,
        public int $maxAttempts,
        public float $jitter,
        public int $recheckDelay,
    ) {
        if ($baseDelay < 1 || $maxDelay < $baseDelay) {
            throw new InvalidArgumentException('The base delay must be at least 1 second and the maximum delay at least the base delay.');
        }

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('There must be at least 1 attempt.');
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new InvalidArgumentException('The jitter must be between 0 and 1.');
        }

        if ($recheckDelay < 1) {
            throw new InvalidArgumentException('The recheck delay must be at least 1 second.');
        }
    }

    public function exhausted(int $attempts): bool
    {
        return $attempts >= $this->maxAttempts;
    }

    /**
     * Seconds to wait after the given number of failed attempts.
     *
     * @param  float  $random  0 to 1; 0 gives the full delay, 1 the shortest
     */
    public function delayAfter(int $attempts, float $random): int
    {
        if ($attempts < 1) {
            throw new InvalidArgumentException('A delay follows at least 1 attempt.');
        }

        if ($random < 0.0 || $random > 1.0) {
            throw new InvalidArgumentException('The random share must be between 0 and 1.');
        }

        // The exponent is bounded so the product cannot overflow: 2 ** 20
        // seconds is twelve days, beyond any sensible maximum.
        $delay = min($this->maxDelay, $this->baseDelay * 2 ** min($attempts - 1, 20));

        return max(1, (int) round($delay * (1 - $this->jitter * $random)));
    }
}
