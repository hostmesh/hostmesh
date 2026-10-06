<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories\Concerns;

/**
 * What the withTwoFactor() factory state stores. Test values, never real
 * ones. A class, because a trait's constants cannot be read from outside.
 */
final class TwoFactorTestValues
{
    public const string TEST_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public const string TEST_RECOVERY_CODE = 'AAAAA-BBBBB-CCCCC-DDDDD';
}
