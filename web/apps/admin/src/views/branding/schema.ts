// SPDX-License-Identifier: Apache-2.0
import type { components } from '@hostmesh/api-client'
import { z } from '@hostmesh/ui'

export type BrandingResource = components['schemas']['BrandingResource']
export type ContrastWarning = BrandingResource['warnings'][number]
type UpdateBrandingRequest = components['schemas']['UpdateBrandingRequest']

// The rules below mirror App\Http\Api\V1\Admin\Branding\UpdateBrandingRequest
// so that a mistake shows before the request is sent. They are input
// ergonomics: the API validates again and is the authority (spec section 29).

// `#rrggbb`, no shorthand and no alpha (App\Domain\Branding\HexColor), or empty.
const color = z
  .string()
  .trim()
  .regex(/^(#[0-9a-fA-F]{6})?$/, 'Enter a color as #rrggbb, for example #1a2b3c, or leave it empty.')

function isHttpsUrl(value: string): boolean {
  try {
    return new URL(value).protocol === 'https:'
  } catch {
    return false
  }
}

// `https` only: a link is shown to customers and put into emails. Or empty.
const link = z
  .string()
  .trim()
  .max(2048, 'Use at most 2048 characters.')
  .refine((value) => value === '' || isHttpsUrl(value), 'Enter an address that starts with https://, or leave it empty.')

const pair = z.object({ primary: color, primary_foreground: color })

export const brandingSchema = z.object({
  // At least one visible character and no control characters: the name
  // goes into page titles and email subjects.
  product_name: z
    .string()
    .max(60, 'Use at most 60 characters.')
    .regex(/^(?=.*\S)[^\p{C}]+$/u, 'Enter a name. It cannot contain control characters.'),
  colors: z.object({ light: pair, dark: pair }),
  links: z.object({ support: link, terms: link, privacy: link }),
})

export type BrandingFormValues = z.infer<typeof brandingSchema>

/** Every field of the form, by its vee-validate path, which is also the key of the API's errors. */
export const BRANDING_FIELDS = [
  'product_name',
  'colors.light.primary',
  'colors.light.primary_foreground',
  'colors.dark.primary',
  'colors.dark.primary_foreground',
  'links.support',
  'links.terms',
  'links.privacy',
] as const

/** A value that is not configured is an empty field. */
export function toFormValues(branding: BrandingResource): BrandingFormValues {
  const text = (value: string | null): string => value ?? ''
  return {
    product_name: branding.product_name,
    colors: {
      light: {
        primary: text(branding.colors.light.primary),
        primary_foreground: text(branding.colors.light.primary_foreground),
      },
      dark: {
        primary: text(branding.colors.dark.primary),
        primary_foreground: text(branding.colors.dark.primary_foreground),
      },
    },
    links: {
      support: text(branding.links.support),
      terms: text(branding.links.terms),
      privacy: text(branding.links.privacy),
    },
  }
}

/** PUT replaces the record: every field is sent, and an empty one as null, which clears it. */
export function toRequest(values: BrandingFormValues): UpdateBrandingRequest {
  const value = (text: string): string | null => (text === '' ? null : text)
  return {
    product_name: values.product_name,
    colors: {
      light: {
        primary: value(values.colors.light.primary),
        primary_foreground: value(values.colors.light.primary_foreground),
      },
      dark: {
        primary: value(values.colors.dark.primary),
        primary_foreground: value(values.colors.dark.primary_foreground),
      },
    },
    links: {
      support: value(values.links.support),
      terms: value(values.links.terms),
      privacy: value(values.links.privacy),
    },
  }
}

const MODES: Record<string, string> = { light: 'Light mode', dark: 'Dark mode' }
const COLORS: Record<string, string> = {
  primary: 'the primary color',
  primary_foreground: 'the text color on primary',
}
const SURFACES: Record<string, string> = {
  primary: 'the primary color',
  background: 'the page background',
  card: 'the card background',
}

/**
 * A contrast warning of the API in words. The API decides what fails
 * (App\Domain\Branding\ContrastCheck); this only says it. A value this
 * app does not know yet is shown as it is.
 */
export function describeWarning(warning: ContrastWarning): string {
  const mode = MODES[warning.mode] ?? warning.mode
  const color = COLORS[warning.color] ?? warning.color
  const surface = SURFACES[warning.against] ?? warning.against
  return `${mode}: ${color} has a contrast of ${warning.ratio.toFixed(2)}:1 against ${surface}. At least ${warning.required}:1 is recommended (WCAG AA).`
}
