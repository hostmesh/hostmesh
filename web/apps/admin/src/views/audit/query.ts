// SPDX-License-Identifier: Apache-2.0
import type { paths } from '@hostmesh/api-client'
import type { LocationQuery, LocationQueryValue } from 'vue-router'

import { isAction, isActorType } from './labels'

/** What the audit log is asked for (decision 0019): filters, order and the cursor of a page. */
export type AuditQuery = NonNullable<paths['/admin/audit-events']['get']['parameters']['query']>

function first(value: LocationQueryValue | LocationQueryValue[] | undefined): string | undefined {
  const single = Array.isArray(value) ? value[0] : value
  return single === null || single === undefined || single === '' ? undefined : single
}

/**
 * The audit query an address holds. Anything the screen does not offer is
 * left out, so a hand-edited address cannot turn into a request the API
 * refuses. Newest first is the API's default and is never written out.
 */
export function auditQuery(location: LocationQuery): AuditQuery {
  const action = first(location.action)
  const actorType = first(location.actor_type)
  const resource = first(location.resource)
  const from = first(location.from)
  const to = first(location.to)

  return withoutEmpty({
    action: action !== undefined && isAction(action) ? action : undefined,
    actor_type: actorType !== undefined && isActorType(actorType) ? actorType : undefined,
    resource: resource !== undefined && resource.length <= 64 ? resource : undefined,
    from: from !== undefined && isInstant(from) ? from : undefined,
    to: to !== undefined && isInstant(to) ? to : undefined,
    sort: first(location.sort) === 'created_at' ? 'created_at' : undefined,
    cursor: first(location.cursor),
  })
}

/** The query without its unset entries, as the address and the request carry it. */
export function withoutEmpty(query: AuditQuery): AuditQuery {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== undefined && value !== ''),
  ) as AuditQuery
}

function isInstant(value: string): boolean {
  return !Number.isNaN(new Date(value).getTime())
}
