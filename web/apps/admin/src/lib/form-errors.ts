// SPDX-License-Identifier: Apache-2.0
import type { FieldErrors } from '@hostmesh/api-client'
import { nextTick } from 'vue'

/**
 * The errors of the fields a form has, typed by their names, ready for
 * vee-validate's `setErrors`. The API may report a field the form does not
 * show; it is left out.
 */
export function pick<T extends string>(errors: FieldErrors, fields: readonly T[]): Partial<Record<T, string[]>> {
  const picked: Partial<Record<T, string[]>> = {}
  for (const field of fields) {
    const messages = errors[field]
    if (messages !== undefined) {
      picked[field] = messages
    }
  }
  return picked
}

/** After a failed submit: moves focus to the first control `FormField` marked invalid. */
export async function focusFirstInvalid(form: HTMLElement | null): Promise<void> {
  // The errors reach the DOM on the next render.
  await nextTick()
  form?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
