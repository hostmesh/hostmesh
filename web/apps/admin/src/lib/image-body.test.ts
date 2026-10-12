// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { imageBody } from './image-body'

describe('imageBody', () => {
  it('puts the file under `file`, the field the API reads', () => {
    const file = new File(['png'], 'logo.png', { type: 'image/png' })

    const body = imageBody(file)

    expect(body).toBeInstanceOf(FormData)
    expect([...body.keys()]).toEqual(['file'])
    expect(body.get('file')).toBe(file)
  })
})
