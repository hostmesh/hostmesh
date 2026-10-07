<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

/** What an account still has to do before its session is complete. */
enum SecondFactorStep: string
{
    case Challenge = 'challenge';
    case Enrollment = 'enrollment';

    /** The `code` of the 403 response. Clients switch on it. */
    public function errorCode(): string
    {
        return match ($this) {
            self::Challenge => 'two_factor_required',
            self::Enrollment => 'two_factor_enrollment_required',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Challenge => 'Two-factor authentication is required.',
            self::Enrollment => 'Two-factor authentication must be set up first.',
        };
    }
}
