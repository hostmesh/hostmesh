<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Renders the Content-Security-Policy header value of a static app
 * (spec section 28) from the directives in config/hostmesh.php
 */
final readonly class ContentSecurityPolicy
{
    protected const string API_PLACEHOLDER = '{api}';

    /** @param array<string, array<string, list<string>>> $policies */
    public function __construct(
        protected Hostnames $hostnames,
        protected array $policies,
    ) {
        //
    }

    public static function fromConfig(mixed $config, Hostnames $hostnames): self
    {
        $invalid = new InvalidArgumentException(
            'hostmesh.csp must map an app to its directives, and each directive to a list of sources.'
        );

        if (! is_array($config)) {
            throw $invalid;
        }

        $policies = [];

        foreach ($config as $app => $directives) {
            if (! is_string($app) || ! is_array($directives)) {
                throw $invalid;
            }

            $policies[$app] = [];

            foreach ($directives as $directive => $sources) {
                if (! is_string($directive) || ! is_array($sources) || ! array_is_list($sources)) {
                    throw $invalid;
                }

                $policies[$app][$directive] = array_map(
                    fn (mixed $source): string => is_string($source) ? $source : throw $invalid,
                    $sources,
                );
            }
        }

        return new self($hostnames, $policies);
    }

    public function header(string $app): string
    {
        $policy = $this->policies[$app]
            ?? throw new InvalidArgumentException("No Content Security Policy is defined for [$app]");

        $directives = [];

        foreach ($policy as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', array_map(
                fn (string $source): string => $source === self::API_PLACEHOLDER
                    ? $this->hostnames->apiOrigin()
                    : $source,
                $sources,
            ));
        }

        return implode('; ', $directives);
    }
}
