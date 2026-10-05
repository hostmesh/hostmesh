<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The hostname layout of spec section 28, derived from the base domain.
 */
final readonly class Hostnames
{
    protected const string PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public string $domain;

    public function __construct(
        string $domain,
    ) {
        $domain = mb_strtolower(trim($domain));

        if (preg_match(self::PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException(
                "HOSTMESH_DOMAIN must be a bare domain name such as example.com, got [$domain]."
            );
        }

        $this->domain = $domain;
    }

    public function host(string $label): string
    {
        return $label.'.'.$this->domain;
    }

    public function origin(string $label): string
    {
        return 'https://'.$this->host($label);
    }

    public function portalOrigin(): string
    {
        return $this->origin('app');
    }

    public function adminOrigin(): string
    {
        return $this->origin('admin');
    }

    public function apiOrigin(): string
    {
        return $this->origin('api');
    }

    public function apiHost(): string
    {
        return $this->host('api');
    }

    public function agentHost(): string
    {
        return $this->host('agent');
    }

    /** @return list<string> */
    public function spaOrigins(): array
    {
        return [$this->portalOrigin(), $this->adminOrigin()];
    }
}
