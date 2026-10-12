// SPDX-License-Identifier: Apache-2.0
import type { ColorMode } from '@hostmesh/ui'

import type { Branding } from '../stores/branding'

// The tokens branding can override (spec section 28: `primary` and
// `primary_foreground`, for light and for dark).
const TOKENS = [
  ['primary', '--primary'],
  ['primary_foreground', '--primary-foreground'],
] as const

// What the API stores (App\Domain\Branding\HexColor). Checked again here:
// the value goes into a style property.
const HEX_COLOR = /^#[0-9a-f]{6}$/i

/**
 * Spec section 29, theming layer 2: brand colors as runtime token
 * overrides. Sets the tokens on `root` (the `<html>` element) for the
 * current color mode, where they win over tokens.css and over a theme's
 * stylesheet. A color that is not configured removes the override, so the
 * token falls back to its default.
 */
export function applyBrandColors(root: HTMLElement, colors: Branding['colors'], mode: ColorMode): void {
  for (const [key, token] of TOKENS) {
    const value = colors[mode][key]
    if (value !== null && HEX_COLOR.test(value)) {
      root.style.setProperty(token, value)
    } else {
      root.style.removeProperty(token)
    }
  }
}
