// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'

import { focusFirstInvalid, pick } from './form-errors'

afterEach(() => {
  document.body.innerHTML = ''
})

describe('pick', () => {
  it('keeps the errors of the named fields only', () => {
    const errors = { 'email': ['Wrong.'], 'colors.light.primary': ['Bad color.'], 'other': ['Ignored.'] }

    expect(pick(errors, ['email', 'colors.light.primary', 'password'])).toEqual({
      'email': ['Wrong.'],
      'colors.light.primary': ['Bad color.'],
    })
  })
})

describe('focusFirstInvalid', () => {
  it('focuses the first control marked invalid', async () => {
    document.body.innerHTML = `
      <form>
        <input id="a">
        <input id="b" aria-invalid="true">
        <input id="c" aria-invalid="true">
      </form>`

    await focusFirstInvalid(document.querySelector('form'))

    expect(document.activeElement?.id).toBe('b')
  })

  it('leaves the focus alone when nothing is invalid', async () => {
    document.body.innerHTML = '<form><input id="a"></form>'

    await focusFirstInvalid(document.querySelector('form'))

    expect(document.activeElement).toBe(document.body)
  })

  it('accepts a form that is not there', async () => {
    await expect(focusFirstInvalid(null)).resolves.toBeUndefined()
  })
})
