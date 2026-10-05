<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final readonly class Realms
{
    /** @param  array<string, Realm>  $realms */
    public function __construct(
        protected array $realms,
    ) {
        //
    }

    public static function fromConfig(mixed $config, Hostnames $hostnames): self
    {
        if (! is_array($config)) {
            throw new InvalidArgumentException('hostmesh.realms must be an array.');
        }

        $realms = [];

        foreach ($config as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                throw new InvalidArgumentException('hostmesh.realms must map a name to its settings.');
            }

            $lifetime = $settings['session_lifetime'] ?? null;

            if (! is_int($lifetime) || $lifetime < 1) {
                throw new InvalidArgumentException(
                    "hostmesh.realms.$name.session_lifetime must be a positive number of minutes."
                );
            }

            $realms[$name] = new Realm(
                name: $name,
                guard: self::string($name, $settings, 'guard'),
                origin: $hostnames->origin(self::string($name, $settings, 'subdomain')),
                sessionCookie: self::string($name, $settings, 'session_cookie'),
                sessionTable: self::string($name, $settings, 'session_table'),
                csrfCookie: self::string($name, $settings, 'csrf_cookie'),
                sessionLifetime: $lifetime,
                cookieDomain: $hostnames->domain,
            );
        }

        return new self($realms);
    }

    public function get(string $name): Realm
    {
        return $this->realms[$name]
            ?? throw new InvalidArgumentException("Unknown session realm [$name].");
    }

    /** @param array<array-key, mixed> $settings */
    protected static function string(string $realm, array $settings, string $key): string
    {
        $value = $settings[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("hostmesh.realms.$realm.$key must be a non-empty string.");
        }

        return $value;
    }
}
