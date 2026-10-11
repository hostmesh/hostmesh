// SPDX-License-Identifier: Apache-2.0

/** The `errors` of a 422 response: the messages of each field, by its dotted path. */
export type FieldErrors = Record<string, string[]>

/** What a session still owes before it is complete (spec section 28). */
export type SecondFactorStep = 'challenge' | 'enrollment'

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/** The field errors of an error body, or an empty object when it has none. */
export function validationErrors(error: unknown): FieldErrors {
  if (!isRecord(error) || !isRecord(error.errors)) {
    return {}
  }
  const fields: FieldErrors = {}
  for (const [field, messages] of Object.entries(error.errors)) {
    if (Array.isArray(messages)) {
      fields[field] = messages.filter((message): message is string => typeof message === 'string')
    }
  }
  return fields
}

/** The `message` of an error body. */
export function errorMessage(error: unknown): string | undefined {
  return isRecord(error) && typeof error.message === 'string' && error.message !== '' ? error.message : undefined
}

/**
 * The step a 403 body asks for. The API answers every route behind the
 * second factor with `code` (App\Http\Middleware\RequireSecondFactor); the
 * OpenAPI document does not describe the field, so it is read here.
 */
export function pendingSecondFactor(error: unknown): SecondFactorStep | undefined {
  if (!isRecord(error)) {
    return undefined
  }
  if (error.code === 'two_factor_required') {
    return 'challenge'
  }
  return error.code === 'two_factor_enrollment_required' ? 'enrollment' : undefined
}

/** Narrows `meta.second_factor` of a login response, which the schema types as a string. */
export function secondFactorStep(value: string): SecondFactorStep | 'none' {
  return value === 'challenge' || value === 'enrollment' ? value : 'none'
}
