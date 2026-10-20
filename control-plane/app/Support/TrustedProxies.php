<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The proxies whose X-Forwarded-For the control plane believes (decision
 * 0018): addresses and CIDR ranges from HOSTMESH_TRUSTED_PROXIES. None by
 * default. Nothing that trusts every address is accepted, because then a
 * client that reaches the server directly chooses its own address.
 */
final readonly class TrustedProxies
{
    /** What Laravel's TrustProxies middleware reads as "trust whoever connects". */
    protected const array EVERYONE = ['*', '**', 'REMOTE_ADDR'];

    /**
     * @param  list<string>  $addresses
     */
    protected function __construct(
        public array $addresses,
    ) {
        //
    }

    public static function fromSetting(string $setting): self
    {
        $addresses = [];

        foreach (explode(',', $setting) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (in_array($entry, self::EVERYONE, true) || self::prefixLength($entry) === 0) {
                throw new InvalidArgumentException(
                    "HOSTMESH_TRUSTED_PROXIES does not accept [$entry]: it would trust every address. Name the proxies' addresses or ranges."
                );
            }

            if (! self::isAddressOrRange($entry)) {
                throw new InvalidArgumentException(
                    "HOSTMESH_TRUSTED_PROXIES must list IP addresses or CIDR ranges, separated by commas. [$entry] is neither."
                );
            }

            $addresses[] = $entry;
        }

        return new self($addresses);
    }

    protected static function isAddressOrRange(string $entry): bool
    {
        [$address] = explode('/', $entry, 2);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return true;
        }

        $length = self::prefixLength($entry);
        $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? 128 : 32;

        return $length !== null && $length <= $maximum;
    }

    /** The prefix length of a range, or null when the entry has none or it is not a number. */
    protected static function prefixLength(string $entry): ?int
    {
        $parts = explode('/', $entry, 2);

        return isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
    }
}
