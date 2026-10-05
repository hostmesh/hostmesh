// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

// The source of every component and helper the package ships. Tests and
// fixtures are left out: they define colors on purpose.
const sources = import.meta.glob<string>(['../components/**/*.{vue,ts}', '../lib/**/*.ts', '../composables/**/*.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
})

const shipped = Object.entries(sources).filter(([path]) => !path.includes('__fixtures__') && !path.includes('.test.'))

const palette = [
  'slate',
  'gray',
  'zinc',
  'neutral',
  'stone',
  'red',
  'orange',
  'amber',
  'yellow',
  'lime',
  'green',
  'emerald',
  'teal',
  'cyan',
  'sky',
  'blue',
  'indigo',
  'violet',
  'purple',
  'fuchsia',
  'pink',
  'rose',
].join('|')

const hardCodedColors = [
  // Tailwind's palette utilities: `bg-black/80`, `text-white`, `border-zinc-200`.
  new RegExp(`(?<![\\w-])[a-z-]+-(?:black|white|(?:${palette})-\\d{2,3})(?![\\w-])`, 'g'),
  // Arbitrary values and CSS: `#fff`, `rgb(0 0 0)`, `oklch(0.5 0 0)`. A
  // function around a variable, such as `hsl(var(--popover))`, is a token.
  /#[0-9a-f]{3,8}(?![\w-])/gi,
  /\b(?:rgba?|hsla?|oklch|oklab|lab|lch|hwb)\(\s*[\d.]/g,
]

describe('components', () => {
  it('finds the sources to check', () => {
    expect(shipped.length).toBeGreaterThan(20)
  })

  it('take every color from a design token', () => {
    const found = shipped.flatMap(([path, source]) =>
      hardCodedColors.flatMap(pattern => [...source.matchAll(pattern)].map(match => `${path}: ${match[0]}`)),
    )

    expect(found).toEqual([])
  })
})
