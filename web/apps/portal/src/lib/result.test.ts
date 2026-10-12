// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { failure, UNREACHABLE } from './result'

describe('failure', () => {
  it('reports a request that got no response', () => {
    expect(failure(undefined, undefined)).toEqual({ ok: false, errors: {}, message: UNREACHABLE })
  })

  it('returns the field errors of a 422 and no general message', () => {
    const body = { message: 'Invalid.', errors: { email: ['These credentials do not match our records.'] } }

    expect(failure(new Response(null, { status: 422 }), body)).toEqual({
      ok: false,
      errors: body.errors,
      message: '',
    })
  })

  it('asks the user to wait on a 429', () => {
    const result = failure(new Response(null, { status: 429 }), { message: 'Too Many Attempts.' })

    expect(result).toEqual({ ok: false, errors: {}, message: 'Too many attempts. Wait a minute and try again.' })
  })

  it('passes on the message of any other error', () => {
    expect(failure(new Response(null, { status: 409 }), { message: 'Two-factor is already on.' }).message).toBe(
      'Two-factor is already on.',
    )
  })

  it('has a message when the body has none', () => {
    expect(failure(new Response(null, { status: 500 }), 'oops').message).toBe('Something went wrong. Try again.')
  })
})
