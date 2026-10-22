// SPDX-License-Identifier: Apache-2.0
//
// Bodies typed from the generated schema: a contract change fails the typecheck here.
import type { components, paths } from '@hostmesh/api-client'

import { API, json } from './api'

type UserBody = paths['/admin/auth/user']['get']['responses'][200]['content']['application/json']
type LoginBody = paths['/admin/auth/login']['post']['responses'][200]['content']['application/json']
type BrandingBody = paths['/branding']['get']['responses'][200]['content']['application/json']

export const account = {
  id: '01JABCDEFGHJKMNPQRSTVWXYZ0',
  name: 'Ada Lovelace',
  email: 'ada@example.com',
  two_factor_enabled: true,
  abilities: { view: true, update: true, manage_two_factor: true },
} satisfies components['schemas']['AdministratorResource']

export const userBody = { data: account } satisfies UserBody

export function loginBody(step: 'none' | 'challenge' | 'enrollment') {
  return { data: account, meta: { second_factor: step } } satisfies LoginBody
}

export const brandingBody = {
  data: {
    product_name: 'Acme Hosting',
    logos: {
      light: `${API}/branding/images/logo-light?v=aaa`,
      dark: `${API}/branding/images/logo-dark?v=bbb`,
    },
    favicon: `${API}/branding/images/favicon?v=ccc`,
    colors: {
      light: { primary: '#005f73', primary_foreground: '#ffffff' },
      dark: { primary: '#94d2bd', primary_foreground: '#001219' },
    },
    links: { support: 'https://acme.example/support', terms: null, privacy: null },
  },
} satisfies BrandingBody

export function unauthenticated(): Response {
  return json({ message: 'Unauthenticated.' }, 401)
}

/** The 403 of a session that passed the password only (RequireSecondFactor). */
export function pending(step: 'challenge' | 'enrollment'): Response {
  return step === 'challenge'
    ? json({ message: 'Two-factor authentication is required.', code: 'two_factor_required' }, 403)
    : json(
      { message: 'Two-factor authentication must be set up first.', code: 'two_factor_enrollment_required' },
      403,
    )
}

export function guestRoutes(): Record<string, Response> {
  return { 'GET /branding': json(brandingBody), 'GET /admin/auth/user': unauthenticated() }
}

export function signedInRoutes(): Record<string, Response> {
  return { 'GET /branding': json(brandingBody), 'GET /admin/auth/user': json(userBody) }
}

export const brandingResource = {
  ...brandingBody.data,
  warnings: [{ mode: 'dark', color: 'primary_foreground', against: 'primary', ratio: 2.134, required: 4.5 }],
  abilities: { view: true, update: true },
} satisfies components['schemas']['BrandingResource']

type AuditEventsBody = paths['/admin/audit-events']['get']['responses'][200]['content']['application/json']
type AuditEvent = components['schemas']['AuditEventResource']

export const auditEvent = {
  id: '0199f000-0000-7000-8000-000000000001',
  action: 'branding.updated',
  actor: { type: 'administrator', id: account.id, name: 'Ada Lovelace' },
  resource: { type: 'branding', id: '00000000-0000-4000-8000-000000000001', name: null },
  metadata: { product_name: 'changed' },
  ip_address: '203.0.113.7',
  user_agent: 'Mozilla/5.0',
  request_id: '0199f000-0000-7000-8000-0000000000aa',
  created_at: '2026-10-22T09:30:00+00:00',
  abilities: { view: true },
} satisfies AuditEvent

/** One page of the audit log, with the cursors of the pages around it. */
export function auditEventsBody(
  events: AuditEvent[],
  cursors: { next?: string; prev?: string } = {},
): AuditEventsBody {
  return {
    data: events,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      path: `${API}/admin/audit-events`,
      per_page: 50,
      next_cursor: cursors.next ?? null,
      prev_cursor: cursors.prev ?? null,
    },
  }
}
