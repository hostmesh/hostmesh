// SPDX-License-Identifier: Apache-2.0
import { errorMessage, validationErrors, type FieldErrors } from '@hostmesh/api-client'

export interface Failure {
  ok: false
  /** Messages for form fields, by the field's dotted path. */
  errors: FieldErrors
  /** A message that belongs to no field, or the empty string. */
  message: string
}

/** What a store action or a save returns: the value, or what to show the user. */
export type Result<T = undefined> = { ok: true, value: T } | Failure

export const UNREACHABLE = 'The server could not be reached. Try again.'

/** Turns a failed call into what the form shows. `response` is undefined when no response arrived. */
export function failure(response: Response | undefined, error: unknown): Failure {
  if (response === undefined) {
    return { ok: false, errors: {}, message: UNREACHABLE }
  }
  const errors = validationErrors(error)
  if (response.status === 422 && Object.keys(errors).length > 0) {
    return { ok: false, errors, message: '' }
  }
  if (response.status === 429) {
    return { ok: false, errors: {}, message: 'Too many attempts. Wait a minute and try again.' }
  }
  return { ok: false, errors: {}, message: errorMessage(error) ?? 'Something went wrong. Try again.' }
}
