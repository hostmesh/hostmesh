// SPDX-License-Identifier: Apache-2.0

// A `datetime-local` field holds a wall-clock time without a zone, read in
// the browser's zone. The API and the address bar hold instants in UTC.
const DATE_TIME_LOCAL = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/

/** The instant a `datetime-local` value means, as ISO 8601 in UTC. Undefined for an empty field. */
export function fromDateTimeLocal(value: string): string | undefined {
  if (!DATE_TIME_LOCAL.test(value)) {
    return undefined
  }
  // A date-time without an offset is local time (ECMAScript's date format).
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? undefined : date.toISOString()
}

/** What a `datetime-local` field shows for an instant, to the minute. Empty for nothing. */
export function toDateTimeLocal(instant: string | undefined): string {
  const date = new Date(instant ?? '')
  if (Number.isNaN(date.getTime())) {
    return ''
  }
  const pad = (value: number): string => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
