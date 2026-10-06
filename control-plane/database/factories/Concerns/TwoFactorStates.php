<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Domain\Identity\TwoFactor\RecoveryCodes;

trait TwoFactorStates
{
    /** An account with confirmed two-factor and one recovery code. */
    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => TwoFactorTestValues::TEST_SECRET,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [(new RecoveryCodes)->hash(TwoFactorTestValues::TEST_RECOVERY_CODE)],
        ]);
    }
}
