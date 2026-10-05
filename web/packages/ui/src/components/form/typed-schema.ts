// SPDX-License-Identifier: Apache-2.0
import type { StandardSchemaV1 } from '@standard-schema/spec'
import type { TypedSchema, TypedSchemaError } from 'vee-validate'

/**
 * Makes a Standard Schema (a Zod 4 schema, for one) usable as the
 * `validationSchema` of vee-validate 4, which only knows its own typed
 * schemas. vee-validate 5 accepts Standard Schemas directly; this adapter is
 * removed when it is stable (decision 0009).
 *
 * It reports errors only. It does not fill in defaults (`cast`) or tell
 * vee-validate which fields are required (`describe`); pass `initialValues`
 * to `useForm`.
 */
export function toTypedSchema<TSchema extends StandardSchemaV1>(
  schema: TSchema,
): TypedSchema<StandardSchemaV1.InferInput<TSchema>, StandardSchemaV1.InferOutput<TSchema>> {
  return {
    __type: 'VVTypedSchema',
    async parse(values) {
      const result = await schema['~standard'].validate(values)

      if (!result.issues) {
        return { value: result.value, errors: [] }
      }

      // One entry per field, with its messages in the order they were reported.
      const errors = new Map<string, TypedSchemaError>()
      for (const issue of result.issues) {
        const path = formPath(issue.path)
        const entry = errors.get(path) ?? { path, errors: [] }
        entry.errors.push(issue.message)
        errors.set(path, entry)
      }

      return { errors: [...errors.values()] }
    },
  }
}

// vee-validate names a nested field `owner.email` and an array item
// `tags[1]`. An error on the whole form has the empty path.
function formPath(path: StandardSchemaV1.Issue['path']): string {
  let result = ''
  for (const segment of path ?? []) {
    const key = typeof segment === 'object' ? segment.key : segment
    if (typeof key === 'number') {
      result += `[${key}]`
    } else {
      result += result === '' ? String(key) : `.${String(key)}`
    }
  }
  return result
}
