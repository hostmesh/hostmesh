// SPDX-License-Identifier: Apache-2.0
import type { StandardSchemaV1 } from '@standard-schema/spec'
import { describe, expect, it } from 'vitest'
import * as z from 'zod'

import { toTypedSchema } from './typed-schema'

describe('toTypedSchema', () => {
  it('returns the parsed output and no errors for valid values', async () => {
    const schema = toTypedSchema(z.object({ domain: z.string().trim().min(1) }))

    await expect(schema.parse({ domain: '  example.com ' })).resolves.toEqual({
      value: { domain: 'example.com' },
      errors: [],
    })
  })

  it('reports an error under the field path', async () => {
    const schema = toTypedSchema(z.object({ email: z.email('Enter a valid email address.') }))

    await expect(schema.parse({ email: 'nope' })).resolves.toEqual({
      errors: [{ path: 'email', errors: ['Enter a valid email address.'] }],
    })
  })

  it('writes nested fields with dots and array items with brackets', async () => {
    const schema = toTypedSchema(
      z.object({
        owner: z.object({ email: z.email('Enter a valid email address.') }),
        tags: z.array(z.string().min(2, 'Too short.')),
      }),
    )

    const result = await schema.parse({ owner: { email: 'nope' }, tags: ['ok', 'x'] })

    expect(result.errors).toEqual([
      { path: 'owner.email', errors: ['Enter a valid email address.'] },
      { path: 'tags[1]', errors: ['Too short.'] },
    ])
  })

  it('groups several errors of one field', async () => {
    const schema = toTypedSchema(
      z.object({
        password: z.string().min(12, 'At least 12 characters.').regex(/\d/, 'At least one digit.'),
      }),
    )

    const result = await schema.parse({ password: 'short' })

    expect(result.errors).toEqual([
      { path: 'password', errors: ['At least 12 characters.', 'At least one digit.'] },
    ])
  })

  it('reports an error on the whole form under the empty path', async () => {
    const schema = toTypedSchema(
      z
        .object({ password: z.string(), confirmation: z.string() })
        .refine((values) => values.password === values.confirmation, 'The passwords differ.'),
    )

    const result = await schema.parse({ password: 'a', confirmation: 'b' })

    expect(result.errors).toEqual([{ path: '', errors: ['The passwords differ.'] }])
  })

  it('waits for asynchronous checks', async () => {
    const schema = toTypedSchema(
      z.object({
        domain: z.string().refine(async (value) => value !== 'taken.example', 'Already in use.'),
      }),
    )

    const result = await schema.parse({ domain: 'taken.example' })

    expect(result.errors).toEqual([{ path: 'domain', errors: ['Already in use.'] }])
  })

  it('accepts any Standard Schema, including path segments given as objects', async () => {
    const standard: StandardSchemaV1<{ rows: string[] }> = {
      '~standard': {
        version: 1,
        vendor: 'test',
        validate: () => ({ issues: [{ message: 'Bad row.', path: [{ key: 'rows' }, 0] }] }),
      },
    }

    const result = await toTypedSchema(standard).parse({ rows: ['x'] })

    expect(result.errors).toEqual([{ path: 'rows[0]', errors: ['Bad row.'] }])
  })
})
