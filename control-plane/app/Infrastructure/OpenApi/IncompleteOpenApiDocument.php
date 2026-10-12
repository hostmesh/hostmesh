<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

use Dedoc\Scramble\Contracts\Diagnostics\Diagnostic;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Scramble could not describe part of the API, so no document is written
 * (issue #63). Left alone, Scramble exports what it has: a request without
 * its body, or a type it could not infer as an empty schema or a string.
 */
final class IncompleteOpenApiDocument extends RuntimeException
{
    /**
     * @param  Collection<int, Diagnostic>  $diagnostics
     */
    public static function from(Collection $diagnostics): self
    {
        $lines = $diagnostics->map(function (Diagnostic $diagnostic): string {
            $where = implode(', ', array_map(
                fn (array $detail): string => "{$detail[0]} {$detail[1]}",
                $diagnostic->details(),
            ));

            // Scramble's own schema types are named in full.
            $message = str_replace('Dedoc\\Scramble\\Support\\Generator\\Types\\', '', $diagnostic->message());

            return sprintf('- [%s] %s%s', $diagnostic->code(), $message, $where === '' ? '' : " ({$where})");
        });

        return new self(sprintf(
            "The OpenAPI document was not written: Scramble reported %d %s.\n%s\nSee \"When generation fails\" in control-plane/README.md.",
            $diagnostics->count(),
            $diagnostics->count() === 1 ? 'problem' : 'problems',
            $lines->implode("\n"),
        ));
    }
}
