// SPDX-License-Identifier: Apache-2.0
import type { components } from '@hostmesh/api-client'
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
    expect(wrapper.find('form').exists()).toBe(false)
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
