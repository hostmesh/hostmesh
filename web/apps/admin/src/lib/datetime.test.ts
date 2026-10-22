// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { fromDateTimeLocal, toDateTimeLocal } from './datetime'

// A `datetime-local` field holds a wall-clock time without a zone, in the
// browser's zone. The API and the address bar hold instants in UTC.
describe('datetime-local fields', () => {
  it('turns what the field holds into an instant', () => {
    expect(fromDateTimeLocal('2026-10-01T09:30')).toBe(new Date(2026, 9, 1, 9, 30).toISOString())
  })

  it('turns an instant into what the field holds, in the browser’s zone', () => {
    expect(toDateTimeLocal(new Date(2026, 9, 1, 9, 30).toISOString())).toBe('2026-10-01T09:30')
  })

  it('goes there and back', () => {
    expect(toDateTimeLocal(fromDateTimeLocal('2026-01-31T23:59'))).toBe('2026-01-31T23:59')
  })

  it('treats an empty field and a value that is no time as nothing', () => {
    expect(fromDateTimeLocal('')).toBeUndefined()
    expect(fromDateTimeLocal('soon')).toBeUndefined()
    expect(fromDateTimeLocal('2026-13-45T25:99')).toBeUndefined()
    expect(toDateTimeLocal(undefined)).toBe('')
    expect(toDateTimeLocal('soon')).toBe('')
  })
})
