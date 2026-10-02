// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

describe('component test harness', () => {
  it('computes styles from Tailwind in a real browser', () => {
    const element = document.createElement('div')
    element.className = 'hidden'
    document.body.append(element)

    expect(getComputedStyle(element).display).toBe('none')

    element.remove()
  })
})
