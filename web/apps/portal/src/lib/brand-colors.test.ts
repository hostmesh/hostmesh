// SPDX-License-Identifier: Apache-2.0
import { beforeEach, describe, expect, it } from 'vitest'

import { DEFAULT_BRANDING } from '../stores/branding'
import { applyBrandColors } from './brand-colors'

const colors = {
  light: { primary: '#005f73', primary_foreground: '#ffffff' },
  dark: { primary: '#94d2bd', primary_foreground: '#001219' },
}

let root: HTMLElement

function token(name: string): string {
  return root.style.getPropertyValue(name)
}

beforeEach(() => {
  root = document.createElement('div')
})

describe('applyBrandColors', () => {
  it('overrides the primary pair with the colors of light mode', () => {
    applyBrandColors(root, colors, 'light')

    expect(token('--primary')).toBe('#005f73')
    expect(token('--primary-foreground')).toBe('#ffffff')
  })

  it('switches to the colors of dark mode', () => {
    applyBrandColors(root, colors, 'light')
    applyBrandColors(root, colors, 'dark')

    expect(token('--primary')).toBe('#94d2bd')
    expect(token('--primary-foreground')).toBe('#001219')
  })

  it('touches no other token', () => {
    applyBrandColors(root, colors, 'light')

    expect(root.style).toHaveLength(2)
  })

  it('removes an override when the color is not configured', () => {
    applyBrandColors(root, colors, 'light')
    applyBrandColors(root, { ...colors, dark: { primary: null, primary_foreground: '#001219' } }, 'dark')

    expect(token('--primary')).toBe('')
    expect(token('--primary-foreground')).toBe('#001219')
  })

  it('leaves the tokens alone for the defaults', () => {
    applyBrandColors(root, DEFAULT_BRANDING.colors, 'light')

    expect(root.style).toHaveLength(0)
  })

  it.each(['red', '#fff', 'url(https://evil.example)', '#005f73; background: red'])(
    'ignores %s: only #rrggbb is applied',
    (value) => {
      applyBrandColors(root, { ...colors, light: { primary: value, primary_foreground: null } }, 'light')

      expect(root.style).toHaveLength(0)
    },
  )
})
