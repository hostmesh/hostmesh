// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'
import type { Locator } from 'vitest/browser'
import { render } from 'vitest-browser-vue'

import TokenSample from '../test/TokenSample.vue'

const root = document.documentElement

function styleOf(locator: Locator): CSSStyleDeclaration {
  return getComputedStyle(locator.element())
}

afterEach(() => {
  root.classList.remove('tokens-non-default', 'tokens-partial', 'tokens-partial-dark', 'dark')
})

describe('design tokens', () => {
  describe('with a non-default token set', () => {
    it('colors the page from --background and --foreground', async () => {
      root.classList.add('tokens-non-default')
      await render(TokenSample)

      const body = getComputedStyle(document.body)
      expect(body.backgroundColor).toBe('rgb(10, 20, 30)')
      expect(body.color).toBe('rgb(240, 230, 220)')
    })

    it('colors the default button from --primary and --primary-foreground', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      const button = styleOf(screen.getByRole('button', { name: 'Save' }))
      expect(button.backgroundColor).toBe('rgb(255, 0, 128)')
      expect(button.color).toBe('rgb(0, 255, 128)')
    })

    it('colors the secondary and destructive buttons from their tokens', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      const secondary = styleOf(screen.getByRole('button', { name: 'Cancel' }))
      expect(secondary.backgroundColor).toBe('rgb(40, 80, 120)')
      expect(secondary.color).toBe('rgb(7, 8, 9)')
      expect(styleOf(screen.getByRole('button', { name: 'Delete' })).color).toBe('rgb(128, 0, 255)')
    })

    it('takes the corner radius from --radius', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      expect(styleOf(screen.getByRole('button', { name: 'Save' })).borderTopLeftRadius).toBe('0px')
      expect(styleOf(screen.getByRole('textbox', { name: 'Domain' })).borderTopLeftRadius).toBe('0px')
      expect(styleOf(screen.getByTestId('card')).borderTopLeftRadius).toBe('0px')
    })

    it('takes the fonts from --font-sans and --font-mono', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      expect(styleOf(screen.getByRole('button', { name: 'Save' })).fontFamily).toContain('Courier New')
      expect(styleOf(screen.getByTestId('mono')).fontFamily).toContain('Times New Roman')
    })

    it('borders the input from --input and, when focused, from --ring', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)
      const input = screen.getByRole('textbox', { name: 'Domain' })

      expect(styleOf(input).borderTopColor).toBe('rgb(0, 0, 255)')

      await input.click()
      // The input animates its colors, so wait for the transition to end.
      await expect.poll(() => styleOf(input).borderTopColor).toBe('rgb(255, 128, 0)')
    })

    it('colors the card from --card and --card-foreground', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      const card = styleOf(screen.getByTestId('card'))
      expect(card.backgroundColor).toBe('rgb(20, 40, 60)')
      expect(card.color).toBe('rgb(1, 2, 3)')
    })

    it('maps muted, accent and border into Tailwind utilities', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      expect(styleOf(screen.getByTestId('muted')).color).toBe('rgb(200, 100, 0)')
      expect(styleOf(screen.getByTestId('accent')).backgroundColor).toBe('rgb(60, 120, 180)')
      expect(styleOf(screen.getByTestId('accent')).color).toBe('rgb(10, 11, 12)')
      expect(styleOf(screen.getByTestId('bordered')).borderTopColor).toBe('rgb(0, 128, 0)')
    })

    it('maps the destructive pair into Tailwind utilities', async () => {
      root.classList.add('tokens-non-default')
      const screen = await render(TokenSample)

      // No component reads --destructive-foreground yet: the pair is there
      // for the first one that puts text on a destructive surface.
      const destructive = styleOf(screen.getByTestId('destructive'))
      expect(destructive.backgroundColor).toBe('rgb(128, 0, 255)')
      expect(destructive.color).toBe('rgb(13, 14, 15)')
    })
  })

  describe('with the default token set', () => {
    it('renders the defaults, not the fixture', async () => {
      const screen = await render(TokenSample)

      const button = styleOf(screen.getByRole('button', { name: 'Save' }))
      expect(button.backgroundColor).not.toBe('rgb(255, 0, 128)')
      // --radius is 0.625rem, and the Nova button uses the `lg` step.
      expect(button.borderTopLeftRadius).toBe('10px')
    })
  })

  describe('with a partial token set', () => {
    it('keeps the defaults for the rest', async () => {
      const screen = await render(TokenSample)
      const button = screen.getByRole('button', { name: 'Save' })
      const card = screen.getByTestId('card')
      const defaultCardBackground = styleOf(card).backgroundColor

      root.classList.add('tokens-partial')

      await expect.poll(() => styleOf(button).backgroundColor).toBe('rgb(0, 128, 128)')
      expect(styleOf(card).backgroundColor).toBe(defaultCardBackground)
      expect(styleOf(button).borderTopLeftRadius).toBe('10px')
    })

    // A theme's `:root` and the default `.dark` have the same specificity,
    // and the theme is loaded later: what it sets for light replaces the
    // dark default too.
    it('applies a token set only for light in dark mode too', async () => {
      const screen = await render(TokenSample)
      const button = screen.getByRole('button', { name: 'Save' })

      root.classList.add('dark', 'tokens-partial')

      await expect.poll(() => styleOf(button).backgroundColor).toBe('rgb(0, 128, 128)')
    })

    it('takes the dark value a theme sets under .dark and keeps the other dark defaults', async () => {
      const screen = await render(TokenSample)
      const button = screen.getByRole('button', { name: 'Save' })
      const card = screen.getByTestId('card')
      root.classList.add('dark')
      await expect.poll(() => styleOf(card).backgroundColor).not.toBe('rgb(255, 255, 255)')
      const darkCardBackground = styleOf(card).backgroundColor

      root.classList.add('tokens-partial-dark')

      await expect.poll(() => styleOf(button).backgroundColor).toBe('rgb(128, 128, 0)')
      expect(styleOf(card).backgroundColor).toBe(darkCardBackground)

      root.classList.remove('dark')

      await expect.poll(() => styleOf(button).backgroundColor).toBe('rgb(0, 128, 128)')
    })
  })

  describe('dark mode', () => {
    it('redefines the tokens under .dark', async () => {
      await render(TokenSample)
      const light = {
        token: getComputedStyle(root).getPropertyValue('--background'),
        page: getComputedStyle(document.body).backgroundColor,
      }

      root.classList.add('dark')

      expect(getComputedStyle(root).getPropertyValue('--background')).not.toBe(light.token)
      expect(getComputedStyle(document.body).backgroundColor).not.toBe(light.page)
    })
  })
})
