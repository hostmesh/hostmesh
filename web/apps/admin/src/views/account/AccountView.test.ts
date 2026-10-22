// SPDX-License-Identifier: Apache-2.0
import type { components } from '@hostmesh/api-client'
import { COLOR_MODE_STORAGE_KEY, ColorModeSetting } from '@hostmesh/ui'
import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi, noContent } from '../../test/api'
import { mountApp } from '../../test/app'
import { account, brandingBody, pending } from '../../test/fixtures'
import { submitted } from '../../test/view'

// Issue #138: the account screen, where an administrator replaces the
// recovery codes and turns two-factor off. It is required in admin (spec
// section 28), so turning it off leads straight into setting it up again.

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  localStorage.clear()
  document.documentElement.classList.remove('light', 'dark')
})

const enrollment = {
  secret: 'JBSWY3DPEHPK3PXP',
  provisioning_uri: 'otpauth://totp/Acme%20Hosting:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Acme%20Hosting',
}
const codes = ['aaaaa-bbbbb', 'ccccc-ddddd', 'eeeee-fffff']
const credentials = { password: 'correct-password', code: '123456' }

/** What `get()` of a wrapper returns. */
type Found<T extends Element = Element> = Omit<DOMWrapper<T>, 'exists'>

function user(changes: Partial<components['schemas']['AdministratorResource']> = {}) {
  return json({ data: { ...account, ...changes } })
}

/** The account screen of a signed-in administrator. Change `api.routes` to answer differently. */
async function openAccount(routes: Record<string, Response> = {}) {
  const api = mockApi({
    'GET /branding': json(brandingBody),
    'GET /admin/auth/user': user(),
    ...routes,
  })
  const mounted = await mountApp('/account')
  return { api, ...mounted }
}

function section(wrapper: VueWrapper): Found {
  return wrapper.get('[data-test="two-factor"]')
}

/** The form labelled by the heading `name`. */
function form(wrapper: VueWrapper, name: string): DOMWrapper<HTMLFormElement> {
  const match = wrapper.findAll('form').find((candidate) => {
    const id = candidate.attributes('aria-labelledby')
    return id !== undefined && document.getElementById(id)?.textContent?.trim() === name
  })
  if (match === undefined) {
    throw new Error(`No form labelled "${name}"`)
  }
  return match as DOMWrapper<HTMLFormElement>
}

function field(scope: Found, label: string): Found<HTMLInputElement> {
  const labelElement = scope.findAll('label').find((candidate) => candidate.text() === label)
  if (labelElement === undefined) {
    throw new Error(`No field labelled "${label}"`)
  }
  return scope.get<HTMLInputElement>(`#${CSS.escape(labelElement.attributes('for') ?? '')}`)
}

async function fill(scope: DOMWrapper<HTMLFormElement>, values: { password?: string, code?: string }): Promise<void> {
  if (values.password !== undefined) {
    await field(scope, 'Password').setValue(values.password)
  }
  if (values.code !== undefined) {
    await field(scope, 'Authentication code').setValue(values.code)
  }
  await scope.trigger('submit')
  await submitted()
}

async function click(scope: Found, name: string): Promise<void> {
  const button = scope.findAll('button').find((candidate) => candidate.text() === name)
  if (button === undefined) {
    throw new Error(`No button "${name}"`)
  }
  await button.trigger('click')
  await flushPromises()
}

describe('AccountView', () => {
  // Issue #141: light, dark or the system's setting, for this browser only.
  it('offers the appearance setting, and applies a choice at once', async () => {
    const { wrapper } = await openAccount()

    const preferences = wrapper.get('section[aria-labelledby="preferences-heading"]')
    expect(preferences.get('h2').text()).toBe('Preferences')
    expect(preferences.findComponent(ColorModeSetting).exists()).toBe(true)

    await preferences.get('select').setValue('dark')

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('dark')
  })

  it('shows the name and the email of the administrator', async () => {
    const { wrapper } = await openAccount()

    expect(wrapper.get('main h1').text()).toBe('Account')
    expect(wrapper.get('[data-test="name"]').text()).toBe(account.name)
    expect(wrapper.get('[data-test="email"]').text()).toBe(account.email)
  })

  it('has no two-factor controls without the ability', async () => {
    const { wrapper } = await openAccount({
      'GET /admin/auth/user': user({ abilities: { ...account.abilities, manage_two_factor: false } }),
    })

    expect(wrapper.find('[data-test="two-factor"]').exists()).toBe(false)
    // The password form is the only one left.
    expect(wrapper.findAll('form').map((candidate) => candidate.get('h3').text())).toEqual(['Change password'])
  })

  it('says that two-factor is on and required', async () => {
    const { wrapper } = await openAccount()

    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')
    expect(section(wrapper).text()).toContain('required for administrators')
  })
})

describe('recovery codes', () => {
  it('replaces them and shows the new ones once', async () => {
    const { wrapper, api } = await openAccount({
      'POST /admin/auth/two-factor/recovery-codes': json({ data: { recovery_codes: codes } }),
    })

    await fill(form(wrapper, 'Recovery codes'), credentials)

    expect(await api.sent('POST /admin/auth/two-factor/recovery-codes')[0]?.json()).toEqual(credentials)
    expect(wrapper.findAll('[data-test="recovery-code"]').map((item) => item.text())).toEqual(codes)
    const heading = wrapper.get('[data-test="recovery-codes-heading"]')
    expect(heading.text()).toBe('Save your recovery codes')
    expect(document.activeElement).toBe(heading.element)

    await click(section(wrapper), 'I have saved these codes')

    expect(wrapper.find('[data-test="recovery-code"]').exists()).toBe(false)
    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')
    expect(document.activeElement).toBe(wrapper.get('#two-factor-heading').element)
  })

  it('shows a wrong password on its field and keeps the old codes', async () => {
    const errors = { password: ['The provided password is incorrect.'] }
    const { wrapper } = await openAccount({
      'POST /admin/auth/two-factor/recovery-codes': json({ message: errors.password[0], errors }, 422),
    })

    const regenerate = form(wrapper, 'Recovery codes')
    await fill(regenerate, { password: 'wrong', code: '123456' })

    expect(regenerate.text()).toContain('The provided password is incorrect.')
    expect(document.activeElement).toBe(field(regenerate, 'Password').element)
    expect(wrapper.find('[data-test="recovery-code"]').exists()).toBe(false)
  })
})

describe('turning two-factor off', () => {
  it('sends the password and a code, then sets two-factor up again and comes back', async () => {
    const { wrapper, api, router } = await openAccount({
      'POST /admin/auth/two-factor/disable': noContent(),
      'POST /admin/auth/two-factor': json({ data: enrollment }),
      'POST /admin/auth/two-factor/confirm': json({ data: { recovery_codes: codes } }),
    })
    api.routes['GET /admin/auth/user'] = pending('enrollment')

    await fill(form(wrapper, 'Turn off two-factor authentication'), credentials)

    expect(await api.sent('POST /admin/auth/two-factor/disable')[0]?.json()).toEqual(credentials)
    expect(router.currentRoute.value.name).toBe('two-factor-setup')
    expect(router.currentRoute.value.query).toEqual({ redirect: '/account' })
    expect(api.sent('POST /admin/auth/two-factor')).toHaveLength(1)

    api.routes['GET /admin/auth/user'] = user()
    const setup = wrapper.get('form')
    await setup.get('input').setValue('654321')
    await setup.trigger('submit')
    await submitted()
    await click(wrapper.get('main'), 'I have saved these codes')

    expect(router.currentRoute.value.fullPath).toBe('/account')
    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')
  })

  it('shows a wrong code on its field and stays', async () => {
    const errors = { code: ['The two-factor code is invalid.'] }
    const { wrapper, router } = await openAccount({
      'POST /admin/auth/two-factor/disable': json({ message: errors.code[0], errors }, 422),
    })

    const disable = form(wrapper, 'Turn off two-factor authentication')
    await fill(disable, { password: 'correct-password', code: '000000' })

    expect(disable.text()).toContain('The two-factor code is invalid.')
    expect(document.activeElement).toBe(field(disable, 'Authentication code').element)
    expect(router.currentRoute.value.fullPath).toBe('/account')
  })

  it('shows a message that belongs to no field', async () => {
    const { wrapper } = await openAccount({
      'POST /admin/auth/two-factor/disable': json({ message: 'Too Many Attempts.' }, 429),
    })

    const disable = form(wrapper, 'Turn off two-factor authentication')
    await fill(disable, credentials)

    expect(disable.get('[role="alert"]').text()).toBe('Too many attempts. Wait a minute and try again.')
  })

  it('asks for both before sending anything', async () => {
    const { wrapper, api } = await openAccount()

    await fill(form(wrapper, 'Turn off two-factor authentication'), {})

    expect(wrapper.text()).toContain('Enter your password.')
    expect(wrapper.text()).toContain('Enter the code from your authenticator app.')
    expect(api.requests.filter((request) => request.method === 'POST')).toHaveLength(0)
  })
})

// Issue #133, decision 0023: an account changes its own password with the
// current one, and a code where two-factor is on. The API decides what a
// valid new password is; the form only says what it asks for.
describe('changing the password', () => {
  const NEW = 'a correct horse battery staple'

  async function change(scope: DOMWrapper<HTMLFormElement>, values: { current?: string, next?: string, code?: string }): Promise<void> {
    if (values.current !== undefined) {
      await field(scope, 'Current password').setValue(values.current)
    }
    if (values.next !== undefined) {
      await field(scope, 'New password').setValue(values.next)
    }
    if (values.code !== undefined) {
      await field(scope, 'Authentication code').setValue(values.code)
    }
    await scope.trigger('submit')
    await submitted()
  }

  it('sends the current password, the new one and a code, and says what happened', async () => {
    const { wrapper, api } = await openAccount({ 'POST /admin/auth/password': noContent() })
    const scope = form(wrapper, 'Change password')

    expect(scope.text()).toContain('At least 15 characters')

    await change(scope, { current: 'correct-password', next: NEW, code: '123 456' })

    expect(await api.sent('POST /admin/auth/password')[0]?.json()).toEqual({
      current_password: 'correct-password',
      new_password: NEW,
      code: '123 456',
    })
    expect(scope.get('[role="status"]').text()).toBe('Your password was changed. Your other sessions were signed out.')
    // No password stays in the page.
    expect(field(scope, 'Current password').element.value).toBe('')
    expect(field(scope, 'New password').element.value).toBe('')
  })

  it('shows what the API refused on its field', async () => {
    const errors = { new_password: ['The new password field must be at least 15 characters.'] }
    const { wrapper } = await openAccount({
      'POST /admin/auth/password': json({ message: errors.new_password[0], errors }, 422),
    })
    const scope = form(wrapper, 'Change password')

    await change(scope, { current: 'correct-password', next: 'too short', code: '123456' })

    expect(scope.text()).toContain('The new password field must be at least 15 characters.')
    expect(document.activeElement).toBe(field(scope, 'New password').element)
    expect(scope.find('[role="status"]').exists()).toBe(false)
  })

  it('asks for every field before it sends anything', async () => {
    const { wrapper, api } = await openAccount({ 'POST /admin/auth/password': noContent() })

    await change(form(wrapper, 'Change password'), {})

    expect(api.sent('POST /admin/auth/password')).toHaveLength(0)
  })

  it('has no password form without the ability', async () => {
    const { wrapper } = await openAccount({
      'GET /admin/auth/user': user({ abilities: { ...account.abilities, change_password: false } }),
    })

    expect(wrapper.find('section[aria-labelledby="password-heading"]').exists()).toBe(false)
  })
})
