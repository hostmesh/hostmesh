<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor;

use Exception;
use SensitiveParameter;

/**
 * Single-use codes for an account that has lost its authenticator. Only
 * their hashes are stored. A code carries 100 bits of randomness, so a fast
 * hash is sufficient: there is nothing to guess offline.
 */
final readonly class RecoveryCodes
{
    public const int COUNT = 10;

    // 32 characters, 5 bits each. No I, O, 0 or 1.
    protected const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @return list<string>
     * @throws Exception
     */
    public function generate(): array
    {
        $codes = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            $characters = '';

            for ($j = 0; $j < 20; $j++) {
                $characters .= self::ALPHABET[random_int(0, 31)];
            }

            $codes[] = implode('-', str_split($characters, 5));
        }

        return $codes;
    }

    /** The stored form. Case, dashes and spaces do not matter. */
    public function hash(#[SensitiveParameter] string $code): string
    {
        return hash('sha256', (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($code)));
    }
}
