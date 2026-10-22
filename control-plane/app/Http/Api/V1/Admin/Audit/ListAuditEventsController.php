<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Api\V1\Admin\Audit;

use App\Application\Queries\ListAuditEvents;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;

final class ListAuditEventsController
{
    /**
     * List audit events
     *
     * The audit log, newest first; `sort=created_at` lists it oldest first. Filter by actor, resource, action and
     * time; `from` and `to` are inclusive. A date without a time means midnight: `to=2026-10-08` leaves out the
     * events of that day. Pass `meta.next_cursor` as `cursor` for the next page, with the same `sort`.
     * An event holds identifiers only: `actor.name` and `resource.name` are looked up now and are null when the
     * account no longer exists.
     * Needs the `audit-event.view` permission.
     *
     * @return AnonymousResourceCollection<CursorPaginator<int, AuditEventResource>>
     */
    public function __invoke(ListAuditEventsRequest $request, ListAuditEvents $list): AnonymousResourceCollection
    {
        return AuditEventResource::collection(
            $list->handle($request->filter(), $request->perPage(), $request->encodedCursor(), $request->sort()),
        );
    }
}
