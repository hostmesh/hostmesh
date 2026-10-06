<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor;

use SensitiveParameter;

/**
 * Time-based one-time passwords (RFC 6238): 6 digits, 30-second steps.
 */
interface Totp
{
    /** A new Base32 secret. */
    public function generateSecret(): string;

    /** The otpauth:// URI an authenticator app reads from a QR code. */
    public function provisioningUri(string $issuer, string $account, #[SensitiveParameter] string $secret): string;

    /**
     * The time step the code is valid for, or null when it is not valid.
     * Only steps after $afterStep count, so a code that was accepted once
     * is refused when it is sent again (replay protection).
     */
    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, ?int $afterStep): ?int;
}
