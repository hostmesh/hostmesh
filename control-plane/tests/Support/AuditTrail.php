<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** Reads `audit_events` as stored, oldest first. */
final class AuditTrail
{
    /** @return Collection<int, stdClass> */
    public static function all(): Collection
    {
        return DB::table('audit_events')->orderBy('id')->get();
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_values(self::all()->pluck('action')->all());
    }

    public static function last(): stdClass
    {
        return self::all()->last() ?? throw new RuntimeException('No audit event was recorded.');
    }

    /** @return array<string, mixed> */
    public static function metadata(stdClass $row): array
    {
        return json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Decision 0013: no copies of personal data or secrets. Fails when any
     * stored row contains one of the given strings, in any column. Do not
     * pass a six-digit code: it can occur in a UUID by chance.
     */
    public static function assertHoldsNone(string ...$needles): void
    {
        $stored = mb_strtolower(self::all()->map(fn (stdClass $row): string => json_encode($row, JSON_THROW_ON_ERROR))->implode("\n"));

        expect($stored)->not->toBe('', 'No audit event was recorded, so there is nothing to check.');

        foreach ($needles as $needle) {
            expect(str_contains($stored, mb_strtolower($needle)))->toBeFalse("The audit log holds `$needle`.");
        }
    }
}
