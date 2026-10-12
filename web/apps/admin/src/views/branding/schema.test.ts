// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { brandingResource } from '../../test/fixtures'
import { brandingSchema, describeWarning, toFormValues, toRequest } from './schema'

const valid = toFormValues(brandingResource)

function messages(values: unknown): string[] {
  const result = brandingSchema.safeParse(values)
  return result.success ? [] : result.error.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`)
}

// Mirrors App\Http\Api\V1\Admin\Branding\UpdateBrandingRequest. The API stays the authority.
describe('brandingSchema', () => {
  it('accepts what the API returned', () => {
    expect(messages(valid)).toEqual([])
  })

  it('accepts empty colors and links: they clear the value', () => {
    const empty = { primary: '', primary_foreground: '' }

    expect(
      messages({ ...valid, colors: { light: empty, dark: empty }, links: { support: '', terms: '', privacy: '' } }),
    ).toEqual([])
  })

  it.each(['', '   ', 'Acme\u0000', 'Acme\nHosting'])('refuses the product name %j', (product_name) => {
    expect(messages({ ...valid, product_name })).toEqual([
      'product_name: Enter a name. It cannot contain control characters.',
    ])
  })

  it('refuses a product name of more than 60 characters', () => {
    expect(messages({ ...valid, product_name: 'a'.repeat(61) })).toEqual(['product_name: Use at most 60 characters.'])
    expect(messages({ ...valid, product_name: 'a'.repeat(60) })).toEqual([])
  })

  it.each(['#fff', 'red', '#12345g', '#1234567', 'rgb(0 0 0)', '#005f73ff'])('refuses the color %j', (primary) => {
    expect(messages({ ...valid, colors: { ...valid.colors, light: { ...valid.colors.light, primary } } })).toEqual([
      'colors.light.primary: Enter a color as #rrggbb, for example #1a2b3c, or leave it empty.',
    ])
  })

  it('accepts upper-case hex digits', () => {
    expect(
      messages({ ...valid, colors: { ...valid.colors, dark: { primary: '#ABCDEF', primary_foreground: '' } } }),
    ).toEqual([])
  })

  it.each(['http://acme.example/help', 'acme.example/help', 'mailto:help@acme.example', 'javascript:alert(1)'])(
    'refuses the link %j',
    (support) => {
      expect(messages({ ...valid, links: { ...valid.links, support } })).toEqual([
        'links.support: Enter an address that starts with https://, or leave it empty.',
      ])
    },
  )

  it('refuses a link of more than 2048 characters', () => {
    const terms = `https://acme.example/${'a'.repeat(2048)}`

    expect(messages({ ...valid, links: { ...valid.links, terms } })).toEqual([
      'links.terms: Use at most 2048 characters.',
    ])
  })
})

describe('toFormValues and toRequest', () => {
  it('shows a value that is not configured as an empty field', () => {
    expect(toFormValues(brandingResource).links).toEqual({
      support: 'https://acme.example/support',
      terms: '',
      privacy: '',
    })
  })

  it('sends every field, and null for an empty one', () => {
    const values = brandingSchema.parse({
      ...valid,
      product_name: 'Acme Hosting',
      colors: { light: { primary: ' #005F73 ', primary_foreground: '' }, dark: { primary: '', primary_foreground: '' } },
      links: { support: '', terms: ' https://acme.example/terms ', privacy: '' },
    })

    expect(toRequest(values)).toEqual({
      product_name: 'Acme Hosting',
      colors: {
        light: { primary: '#005F73', primary_foreground: null },
        dark: { primary: null, primary_foreground: null },
      },
      links: { support: null, terms: 'https://acme.example/terms', privacy: null },
    })
  })
})

describe('describeWarning', () => {
  it('says which pair fails, in which mode, and by how much', () => {
    const warning = { mode: 'dark', color: 'primary_foreground', against: 'primary', ratio: 2.134, required: 4.5 }

    expect(describeWarning(warning)).toBe(
      'Dark mode: the text color on primary has a contrast of 2.13:1 against the primary color. At least 4.5:1 is recommended (WCAG AA).',
    )
  })

  it('names the surfaces', () => {
    expect(describeWarning({ mode: 'light', color: 'primary', against: 'card', ratio: 3, required: 4.5 })).toBe(
      'Light mode: the primary color has a contrast of 3.00:1 against the card background. At least 4.5:1 is recommended (WCAG AA).',
    )
  })

  it('shows a value it does not know as it is', () => {
    expect(describeWarning({ mode: 'sepia', color: 'accent', against: 'popover', ratio: 1, required: 4.5 })).toBe(
      'sepia: accent has a contrast of 1.00:1 against popover. At least 4.5:1 is recommended (WCAG AA).',
    )
  })
})
