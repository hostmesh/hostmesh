// SPDX-License-Identifier: Apache-2.0
import { flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { fromDateTimeLocal, toDateTimeLocal } from '../../lib/datetime'
import { json, mockApi } from '../../test/api'
import { auditEvent, auditEventsBody, signedInRoutes } from '../../test/fixtures'
import { mountView } from '../../test/view'
import AuditView from './AuditView.vue'

// Issue #150: the audit log, newest first, filtered, sorted and paged by the
// API (decision 0019). The address holds the view, so a reload or the back
// button shows the same page.

const LIST = 'GET /admin/audit-events'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

/** The query of every request for the list, oldest first. */
function queries(api: ReturnType<typeof mockApi>): Record<string, string>[] {
  return api.sent(LIST).map((request) => Object.fromEntries(new URL(request.url).searchParams))
}

function rows(wrapper: VueWrapper): string[][] {
  return wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
}

function button(wrapper: VueWrapper, name: string) {
  const found = wrapper.findAll('button').find((candidate) => candidate.text() === name)
  if (found === undefined) {
    throw new Error(`No button "${name}"`)
  }
  return found
}

describe('AuditView', () => {
  it('lists the newest events first, without filters', async () => {
    const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(wrapper.get('h1').text()).toBe('Audit log')
    expect(queries(api)).toEqual([{}])
    expect(wrapper.findAll('th').map((header) => header.text())).toEqual([
      'Time',
      'Actor',
      'Action',
      'Resource',
      'Request ID',
    ])
    expect(rows(wrapper)[0]?.slice(1)).toEqual([
      'Ada LovelaceAdministrator',
      'Branding updated',
      'branding00000000-0000-4000-8000-000000000001',
      '0199f000-0000-7000-8000-0000000000aa',
    ])
    expect(wrapper.get('tbody time').attributes('datetime')).toBe('2026-10-22T09:30:00+00:00')
    expect(wrapper.get('th[aria-sort]').attributes('aria-sort')).toBe('descending')
  })

  it('names an actor that has no name', async () => {
    mockApi({
      ...signedInRoutes(),
      [LIST]: json(
        auditEventsBody([
          { ...auditEvent, id: 'e1', actor: { type: 'administrator', id: 'gone', name: null } },
          { ...auditEvent, id: 'e2', actor: { type: 'system', id: null, name: null } },
          { ...auditEvent, id: 'e3', action: 'auth.login.failed', actor: { type: 'user', id: null, name: null } },
        ]),
      ),
    })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(rows(wrapper).map((row) => row[1])).toEqual([
      'Deleted accountAdministrator',
      'SystemSystem',
      'UnknownCustomer',
    ])
  })

  it('shows the resource by its name when it has one', async () => {
    mockApi({
      ...signedInRoutes(),
      [LIST]: json(auditEventsBody([{ ...auditEvent, resource: { type: 'user', id: 'u1', name: 'Grace Hopper' } }])),
    })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(rows(wrapper)[0]?.[3]).toBe('userGrace Hopper')
  })

  it('says so when the administrator may not read the log', async () => {
    mockApi({ ...signedInRoutes(), [LIST]: json({ message: 'This action is unauthorized.' }, 403) })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toBe('You do not have permission to view the audit log.')
  })

  it('offers to try again when the log cannot be loaded', async () => {
    const api = mockApi({ ...signedInRoutes(), [LIST]: () => Promise.reject(new TypeError('Failed to fetch')) })
    const { wrapper } = await mountView(AuditView, '/audit')
    expect(wrapper.get('[role="alert"]').text()).toContain('The audit log could not be loaded.')

    api.routes[LIST] = json(auditEventsBody([auditEvent]))
    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(1)
  })

  it('offers to try again when the API answers with an error', async () => {
    mockApi({ ...signedInRoutes(), [LIST]: json({ message: 'Server Error' }, 500) })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(wrapper.get('[role="alert"]').text()).toContain('The audit log could not be loaded.')
  })

  it('shows what it has no label for as the API sent it', async () => {
    mockApi({
      ...signedInRoutes(),
      [LIST]: json(
        auditEventsBody([
          {
            ...auditEvent,
            action: 'website.created',
            actor: { type: 'robot', id: 'r1', name: 'R2' },
            resource: { type: 'website', id: null, name: null },
            request_id: null,
          },
        ]),
      ),
    })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(rows(wrapper)[0]?.slice(1)).toEqual(['R2robot', 'website.created', 'website—', '—'])
  })

  it('shows what the API refused in the filters', async () => {
    mockApi({
      ...signedInRoutes(),
      [LIST]: json(
        { message: 'The to field must be a date after or equal to from.', errors: { to: ['The to field must be a date after or equal to from.'] } },
        422,
      ),
    })
    const { wrapper } = await mountView(AuditView, '/audit?from=2026-10-02T00:00:00.000Z&to=2026-10-01T00:00:00.000Z')

    expect(wrapper.get('[role="alert"]').text()).toBe('The to field must be a date after or equal to from.')
  })

  describe('pages', () => {
    it('follows the cursors of the API, in the address', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent], { next: 'n1' })) })
      const { wrapper, router } = await mountView(AuditView, '/audit')

      expect(button(wrapper, 'Previous page').attributes('disabled')).toBeDefined()

      api.routes[LIST] = json(auditEventsBody([auditEvent], { prev: 'p2' }))
      await button(wrapper, 'Next page').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({ cursor: 'n1' })
      expect(queries(api).at(-1)).toEqual({ cursor: 'n1' })
      expect(button(wrapper, 'Next page').attributes('disabled')).toBeDefined()

      await button(wrapper, 'Previous page').trigger('click')
      await flushPromises()

      expect(queries(api).at(-1)).toEqual({ cursor: 'p2' })
    })
  })

  describe('sorting', () => {
    it('lists the oldest first when asked, from the first page', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      const { wrapper, router } = await mountView(AuditView, '/audit?cursor=n1&action=branding.updated')

      await button(wrapper, 'Time').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({ action: 'branding.updated', sort: 'created_at' })
      expect(queries(api).at(-1)).toEqual({ action: 'branding.updated', sort: 'created_at' })
      expect(wrapper.get('th[aria-sort]').attributes('aria-sort')).toBe('ascending')

      await button(wrapper, 'Time').trigger('click')
      await flushPromises()

      // Newest first is the default: the address does not say it.
      expect(router.currentRoute.value.query).toEqual({ action: 'branding.updated' })
    })
  })

  describe('filters', () => {
    it('sends the filters from the first page, and keeps the order', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      const { wrapper, router } = await mountView(AuditView, '/audit?sort=created_at&cursor=n1')

      await wrapper.get('select#audit-action').setValue('branding.updated')
      await wrapper.get('select#audit-actor-type').setValue('administrator')
      await wrapper.get('input#audit-resource').setValue('branding')
      await wrapper.get('input#audit-from').setValue('2026-10-01T09:30')
      await wrapper.get('input#audit-to').setValue('2026-10-02T18:00')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      const expected = {
        action: 'branding.updated',
        actor_type: 'administrator',
        resource: 'branding',
        from: fromDateTimeLocal('2026-10-01T09:30'),
        to: fromDateTimeLocal('2026-10-02T18:00'),
        sort: 'created_at',
      }
      expect(router.currentRoute.value.query).toEqual(expected)
      expect(queries(api).at(-1)).toEqual(expected)
    })

    it('shows the filters of the address and sends them', async () => {
      const from = '2026-10-01T07:30:00.000Z'
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      const { wrapper } = await mountView(AuditView, `/audit?action=auth.logout&actor_type=system&from=${from}`)

      expect(queries(api)).toEqual([{ action: 'auth.logout', actor_type: 'system', from }])
      expect((wrapper.get('select#audit-action').element as HTMLSelectElement).value).toBe('auth.logout')
      expect((wrapper.get('select#audit-actor-type').element as HTMLSelectElement).value).toBe('system')
      expect((wrapper.get('input#audit-from').element as HTMLInputElement).value).toBe(toDateTimeLocal(from))
    })

    it('leaves out what it does not know', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      await mountView(AuditView, '/audit?action=website.deleted.maybe&actor_type=robot&sort=action&per_page=500&page=2')

      expect(queries(api)).toEqual([{}])
    })

    it('sends no filter for an empty field', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      const { wrapper, router } = await mountView(AuditView, '/audit?action=auth.logout')

      await wrapper.get('select#audit-action').setValue('')
      await wrapper.get('input#audit-resource').setValue('   ')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({})
      expect(queries(api).at(-1)).toEqual({})
    })

    it('takes the first of a repeated parameter', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      await mountView(AuditView, '/audit?action=auth.logout&action=branding.updated')

      expect(queries(api)).toEqual([{ action: 'auth.logout' }])
    })

    it('clears the filters and keeps the order', async () => {
      const api = mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([auditEvent])) })
      const { wrapper, router } = await mountView(AuditView, '/audit?action=auth.logout&resource=user&sort=created_at')

      await button(wrapper, 'Clear').trigger('click')
      await flushPromises()

      expect(router.currentRoute.value.query).toEqual({ sort: 'created_at' })
      expect(queries(api).at(-1)).toEqual({ sort: 'created_at' })
      expect((wrapper.get('select#audit-action').element as HTMLSelectElement).value).toBe('')
      expect((wrapper.get('input#audit-resource').element as HTMLInputElement).value).toBe('')
    })

    it('offers every action of the API by a label', async () => {
      mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([])) })
      const { wrapper } = await mountView(AuditView, '/audit')

      const options = wrapper.findAll('select#audit-action option')
      expect(options[0]?.text()).toBe('Any action')
      expect(options).toHaveLength(20)
      expect(options.find((option) => option.attributes('value') === 'audit_log.events_deleted')?.text()).toBe(
        'Audit events deleted',
      )
    })
  })

  it('says when there are no events', async () => {
    mockApi({ ...signedInRoutes(), [LIST]: json(auditEventsBody([])) })
    const { wrapper } = await mountView(AuditView, '/audit')

    expect(wrapper.get('tbody').text()).toBe('No audit events match.')
  })
})
