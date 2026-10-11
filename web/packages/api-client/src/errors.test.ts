// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import {
  errorMessage,
  pendingSecondFactor,
  secondFactorStep,
  validationErrors,
  type components,
} from './index'

type ValidationBody = components['responses']['ValidationException']['content']['application/json']

describe('validationErrors', () => {
  it('returns the messages of each field', () => {
    const body = {
      message: 'The email field is required.',
      errors: { 'email': ['The email field is required.'], 'colors.light.primary': ['The format is invalid.'] },
    } satisfies ValidationBody

    expect(validationErrors(body)).toEqual(body.errors)
  })

  it.each([undefined, null, 'Server Error', { message: 'Forbidden' }, { errors: 'nope' }])(
    'returns nothing for %j',
    (body) => {
      expect(validationErrors(body)).toEqual({})
    },
  )
})

describe('errorMessage', () => {
  it('reads the message of an error body', () => {
    expect(errorMessage({ message: 'Too Many Attempts.' })).toBe('Too Many Attempts.')
  })

  it.each([undefined, '', { message: '' }, { message: 5 }])('returns undefined for %j', (body) => {
    expect(errorMessage(body)).toBeUndefined()
  })
})

describe('pendingSecondFactor', () => {
  it.each([
    ['two_factor_required', 'challenge'],
    ['two_factor_enrollment_required', 'enrollment'],
  ] as const)('maps the code %s to %s', (code, step) => {
    expect(pendingSecondFactor({ message: 'x', code })).toBe(step)
  })

  it.each([undefined, { message: 'This action is unauthorized.' }, { code: 'other' }])(
    'returns undefined for %j',
    (body) => {
      expect(pendingSecondFactor(body)).toBeUndefined()
    },
  )
})

describe('secondFactorStep', () => {
  it.each([
    ['challenge', 'challenge'],
    ['enrollment', 'enrollment'],
    ['none', 'none'],
    ['something-new', 'none'],
  ] as const)('narrows %s to %s', (value, step) => {
    expect(secondFactorStep(value)).toBe(step)
  })
})
