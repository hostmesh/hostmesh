// SPDX-License-Identifier: Apache-2.0
import type { components } from '@hostmesh/api-client'
import { COLOR_MODE_STORAGE_KEY, ColorModeSetting, QrCode } from '@hostmesh/ui'
import { flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi, noContent } from '../../test/api'
import { mountApp } from '../../test/app'
import { account, brandingBody } from '../../test/fixtures'
import { submitted } from '../../test/view'

// Issue #139: the account screen, where a customer turns two-factor on and
// off and replaces the recovery codes.

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

function user(changes: Partial<components['schemas']['UserResource']> = {}) {
  return json({ data: { ...account, ...changes } })
}

/** The account screen of a customer with two-factor on or off. Change `api.routes` to answer differently. */
async function openAccount(twoFactor: boolean, routes: Record<string, Response> = {}) {
  const api = mockApi({
    'GET /branding': json(brandingBody),
    'GET /customer/auth/user': user({ two_factor_enabled: twoFactor }),
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
    const { wrapper } = await openAccount(false)

    const preferences = wrapper.get('section[aria-labelledby="preferences-heading"]')
    expect(preferences.get('h2').text()).toBe('Preferences')
    expect(preferences.findComponent(ColorModeSetting).exists()).toBe(true)

    await preferences.get('select').setValue('dark')

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('dark')
  })

  it('shows the name and the email of the customer', async () => {
    const { wrapper } = await openAccount(false)

    expect(wrapper.get('main h1').text()).toBe('Account')
    expect(wrapper.get('[data-test="name"]').text()).toBe(account.name)
    expect(wrapper.get('[data-test="email"]').text()).toBe(account.email)
  })

  it('has no two-factor controls without the ability', async () => {
    mockApi({
      'GET /branding': json(brandingBody),
      'GET /customer/auth/user': user({ abilities: { ...account.abilities, manage_two_factor: false } }),
    })
    const { wrapper } = await mountApp('/account')

    expect(wrapper.find('[data-test="two-factor"]').exists()).toBe(false)
    expect(wrapper.find('form').exists()).toBe(false)
  })
})

describe('turning two-factor on', () => {
  function enrollRoutes(confirm: Response = json({ data: { recovery_codes: codes } })) {
    return {
      'POST /customer/auth/two-factor': json({ data: enrollment }),
      'POST /customer/auth/two-factor/confirm': confirm,
    }
  }

  it('says that two-factor is off and asks for nothing yet', async () => {
    const { wrapper, api } = await openAccount(false, enrollRoutes())

    expect(section(wrapper).text()).toContain('Two-factor authentication is off.')
    expect(wrapper.findComponent(QrCode).exists()).toBe(false)
    expect(api.sent('POST /customer/auth/two-factor')).toHaveLength(0)
  })

  it('shows the QR code and the key when the customer starts', async () => {
    const { wrapper, api } = await openAccount(false, enrollRoutes())

    await click(section(wrapper), 'Turn on two-factor authentication')

    expect(api.sent('POST /customer/auth/two-factor')).toHaveLength(1)
    expect(wrapper.getComponent(QrCode).props('value')).toBe(enrollment.provisioning_uri)
    expect(wrapper.get('[data-test="secret"]').text()).toBe(enrollment.secret)
    const code = field(form(wrapper, 'Set up two-factor authentication'), 'Authentication code')
    expect(code.attributes('autocomplete')).toBe('one-time-code')
    expect(document.activeElement).toBe(code.element)
  })

  it('shows the recovery codes once after a right code, then that two-factor is on', async () => {
    const { wrapper, api, auth } = await openAccount(false, enrollRoutes())
    await click(section(wrapper), 'Turn on two-factor authentication')
    api.routes['GET /customer/auth/user'] = user({ two_factor_enabled: true })

    await fill(form(wrapper, 'Set up two-factor authentication'), { code: '123456' })

    expect(await api.sent('POST /customer/auth/two-factor/confirm')[0]?.json()).toEqual({ code: '123456' })
    expect(auth.user?.two_factor_enabled).toBe(true)
    const heading = wrapper.get('[data-test="recovery-codes-heading"]')
    expect(heading.text()).toBe('Save your recovery codes')
    expect(document.activeElement).toBe(heading.element)
    expect(wrapper.findAll('[data-test="recovery-code"]').map((item) => item.text())).toEqual(codes)
    expect(wrapper.findComponent(QrCode).exists()).toBe(false)

    await click(section(wrapper), 'I have saved these codes')

    expect(wrapper.find('[data-test="recovery-code"]').exists()).toBe(false)
    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')
    expect(document.activeElement).toBe(wrapper.get('#two-factor-heading').element)
  })

  it('shows a wrong code on the field and focuses it', async () => {
    const errors = { code: ['The two-factor code is invalid.'] }
    const { wrapper } = await openAccount(false, enrollRoutes(json({ message: errors.code[0], errors }, 422)))
    await click(section(wrapper), 'Turn on two-factor authentication')

    const setup = form(wrapper, 'Set up two-factor authentication')
    await fill(setup, { code: '000000' })

    expect(setup.text()).toContain('The two-factor code is invalid.')
    expect(document.activeElement).toBe(field(setup, 'Authentication code').element)
    expect(wrapper.findComponent(QrCode).exists()).toBe(true)
  })

  it('asks for a code before sending anything', async () => {
    const { wrapper, api } = await openAccount(false, enrollRoutes())
    await click(section(wrapper), 'Turn on two-factor authentication')

    await fill(form(wrapper, 'Set up two-factor authentication'), { code: '' })

    expect(wrapper.text()).toContain('Enter the code from your authenticator app.')
    expect(api.sent('POST /customer/auth/two-factor/confirm')).toHaveLength(0)
  })

  it('can be cancelled', async () => {
    const { wrapper } = await openAccount(false, enrollRoutes())
    await click(section(wrapper), 'Turn on two-factor authentication')

    await click(section(wrapper), 'Cancel')

    expect(wrapper.findComponent(QrCode).exists()).toBe(false)
    expect(section(wrapper).text()).toContain('Two-factor authentication is off.')
  })

  it('says so when enrollment cannot be started', async () => {
    const { wrapper } = await openAccount(false, {
      'POST /customer/auth/two-factor': json({ message: 'Two-factor authentication is already on.' }, 409),
    })

    await click(section(wrapper), 'Turn on two-factor authentication')

    expect(section(wrapper).get('[role="alert"]').text()).toBe('Two-factor authentication is already on.')
    expect(wrapper.findComponent(QrCode).exists()).toBe(false)
  })
})

describe('with two-factor on', () => {
  it('replaces the recovery codes and shows the new ones once', async () => {
    const { wrapper, api } = await openAccount(true, {
      'POST /customer/auth/two-factor/recovery-codes': json({ data: { recovery_codes: codes } }),
    })
    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')

    await fill(form(wrapper, 'Recovery codes'), credentials)

    expect(await api.sent('POST /customer/auth/two-factor/recovery-codes')[0]?.json()).toEqual(credentials)
    expect(wrapper.findAll('[data-test="recovery-code"]').map((item) => item.text())).toEqual(codes)
    expect(document.activeElement).toBe(wrapper.get('[data-test="recovery-codes-heading"]').element)

    await click(section(wrapper), 'I have saved these codes')

    expect(wrapper.find('[data-test="recovery-code"]').exists()).toBe(false)
  })

  it('shows a wrong password on its field and keeps the old codes', async () => {
    const errors = { password: ['The provided password is incorrect.'] }
    const { wrapper } = await openAccount(true, {
      'POST /customer/auth/two-factor/recovery-codes': json({ message: errors.password[0], errors }, 422),
    })

    const regenerate = form(wrapper, 'Recovery codes')
    await fill(regenerate, { password: 'wrong', code: '123456' })

    expect(regenerate.text()).toContain('The provided password is incorrect.')
    expect(document.activeElement).toBe(field(regenerate, 'Password').element)
    expect(wrapper.find('[data-test="recovery-code"]').exists()).toBe(false)
  })

  it('turns two-factor off with the password and a code', async () => {
    const { wrapper, api, auth } = await openAccount(true, {
      'POST /customer/auth/two-factor/disable': noContent(),
    })
    api.routes['GET /customer/auth/user'] = user({ two_factor_enabled: false })

    await fill(form(wrapper, 'Turn off two-factor authentication'), credentials)

    expect(await api.sent('POST /customer/auth/two-factor/disable')[0]?.json()).toEqual(credentials)
    expect(auth.user?.two_factor_enabled).toBe(false)
    expect(section(wrapper).text()).toContain('Two-factor authentication is off.')
    expect(document.activeElement).toBe(wrapper.get('#two-factor-heading').element)
  })

  it('shows a wrong code on its field and keeps two-factor on', async () => {
    const errors = { code: ['The two-factor code is invalid.'] }
    const { wrapper } = await openAccount(true, {
      'POST /customer/auth/two-factor/disable': json({ message: errors.code[0], errors }, 422),
    })

    const disable = form(wrapper, 'Turn off two-factor authentication')
    await fill(disable, { password: 'correct-password', code: '000000' })

    expect(disable.text()).toContain('The two-factor code is invalid.')
    expect(document.activeElement).toBe(field(disable, 'Authentication code').element)
    expect(section(wrapper).text()).toContain('Two-factor authentication is on.')
  })

  it('shows a message that belongs to no field', async () => {
    const { wrapper } = await openAccount(true, {
      'POST /customer/auth/two-factor/disable': json({ message: 'Too Many Attempts.' }, 429),
    })

    const disable = form(wrapper, 'Turn off two-factor authentication')
    await fill(disable, credentials)

    expect(disable.get('[role="alert"]').text()).toBe('Too many attempts. Wait a minute and try again.')
  })

  it('asks for both before sending anything', async () => {
    const { wrapper, api } = await openAccount(true)

    await fill(form(wrapper, 'Turn off two-factor authentication'), {})

    expect(wrapper.text()).toContain('Enter your password.')
    expect(wrapper.text()).toContain('Enter the code from your authenticator app.')
    expect(api.requests.filter((request) => request.method === 'POST')).toHaveLength(0)
  })
})
