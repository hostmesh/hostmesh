<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditEventRecord;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class ListAuditEvents
{
    public const int DEFAULT_PER_PAGE = 50;

    /**
     * What the paginator gets when there is no usable cursor. Given null,
     * it would read `cursor` from the current request by itself, and the
     * check in cursor() would be skipped.
     */
    protected const string FIRST_PAGE = '';

    /**
     * The audit log by time, newest first unless asked otherwise
     * (decision 0013, issue #143). `created_at` holds whole seconds, so
     * events of one second follow their key, a UUIDv7 generated on
     * insert: the order of writing. Display names are resolved here, at
     * read time, and stay null for an account that is gone.
     *
     * @param  string|null  $cursor  the `next_cursor` or `prev_cursor` of an earlier page in the same order
     * @return CursorPaginator<int, AuditEventRecord>
     */
    public function handle(
        AuditEventFilter $filter,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?string $cursor = null,
        AuditEventSort $sort = AuditEventSort::NewestFirst,
    ): CursorPaginator {
        $query = AuditEventRecord::query();

        if ($filter->actorType !== null) {
            $query->where('actor_type', $filter->actorType->value);
        }

        if ($filter->actorId !== null) {
            $query->where('actor_id', $filter->actorId);
        }

        if ($filter->resource !== null) {
            $query->where('resource', $filter->resource);
        }

        if ($filter->resourceId !== null) {
            $query->where('resource_id', $filter->resourceId);
        }

        if ($filter->action !== null) {
            $query->where('action', $filter->action->value);
        }

        if ($filter->from !== null) {
            $query->where('created_at', '>=', $filter->from);
        }

        if ($filter->to !== null) {
            $query->where('created_at', '<=', $filter->to);
        }

        $page = $query
            ->orderBy('created_at', $sort->direction())
            ->orderBy('id', $sort->direction())
            ->cursorPaginate($perPage, ['*'], 'cursor', $this->cursor($cursor) ?? self::FIRST_PAGE);

        $this->resolveNames($page->items());

        return $page;
    }

    /**
     * A cursor is the client's: one that this query did not issue starts
     * at the first page instead of reaching the database.
     */
    protected function cursor(?string $encoded): ?Cursor
    {
        $cursor = Cursor::fromEncoded($encoded);

        if ($cursor === null) {
            return null;
        }

        $id = $cursor->toArray()['id'] ?? null;
        $at = $cursor->toArray()['created_at'] ?? null;

        // The time as the paginator writes it, so nothing else reaches PostgreSQL.
        $valid = is_string($id) && Str::isUuid($id)
            && is_string($at) && CarbonImmutable::canBeCreatedFromFormat($at, 'Y-m-d H:i:s');

        return $valid ? $cursor : null;
    }

    /** @param  array<array-key, AuditEventRecord>  $records */
    protected function resolveNames(array $records): void
    {
        $ids = [ActorType::Administrator->value => [], ActorType::User->value => []];

        foreach ($records as $record) {
            foreach ([[$record->actor_type, $record->actor_id], [$record->resource, $record->resource_id]] as [$type, $id]) {
                if ($id !== null && array_key_exists($type, $ids)) {
                    $ids[$type][$id] = true;
                }
            }
        }

        $names = [
            ActorType::Administrator->value => Administrator::query()
                ->whereKey(array_keys($ids[ActorType::Administrator->value]))->pluck('name', 'id'),
            ActorType::User->value => User::query()
                ->whereKey(array_keys($ids[ActorType::User->value]))->pluck('name', 'id'),
        ];

        foreach ($records as $record) {
            $record->actorName = $this->name($names, $record->actor_type, $record->actor_id);
            $record->resourceName = $this->name($names, $record->resource, $record->resource_id);
        }
    }

    /** @param  array<string, Collection<array-key, mixed>>  $names */
    protected function name(array $names, string $type, ?string $id): ?string
    {
        if ($id === null || ! array_key_exists($type, $names)) {
            return null;
        }

        $name = $names[$type]->get($id);

        return is_string($name) ? $name : null;
    }
}
