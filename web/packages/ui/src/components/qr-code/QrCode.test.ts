// SPDX-License-Identifier: Apache-2.0
import { encode } from 'uqr'
import { afterEach, describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import { QrCode } from '../../index'

const uri = 'otpauth://totp/Hostmesh:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Hostmesh'

function svgOf(container: HTMLElement): SVGSVGElement {
  const svg = container.querySelector('svg')
  if (svg === null) {
    throw new Error('No <svg> was rendered.')
  }
  return svg
}

afterEach(() => {
  document.documentElement.classList.remove('dark')
})

describe('QrCode', () => {
  it('is an image with a text alternative', async () => {
    const screen = await render(QrCode, { props: { value: uri, label: 'QR code for your authenticator app' } })

    await expect.element(screen.getByRole('img', { name: 'QR code for your authenticator app' })).toBeVisible()
  })

  it('draws one square for every dark module, with a quiet zone', async () => {
    const screen = await render(QrCode, { props: { value: uri, label: 'QR' } })
    const { data, size } = encode(uri, { border: 4 })
    const dark = data.flat().filter(Boolean).length

    const svg = svgOf(screen.container)
    const d = svg.querySelector('path')?.getAttribute('d') ?? ''
    expect(svg.getAttribute('viewBox')).toBe(`0 0 ${size} ${size}`)
    expect(d.match(/M/g)).toHaveLength(dark)
    // The top-left finder pattern starts right after the quiet zone.
    expect(d.startsWith('M4 4h1v1h-1z')).toBe(true)
  })

  it('takes its colors from the tokens and has no inline style', async () => {
    const screen = await render(QrCode, { props: { value: uri, label: 'QR' } })

    expect(screen.container.innerHTML).not.toContain('style=')
    expect(screen.container.innerHTML).not.toMatch(/#[0-9a-f]{3,6}\b/i)
    expect(svgOf(screen.container).querySelector('path')?.getAttribute('fill')).toBe('currentColor')
  })

  // Issue #124: light modules on a dark field do not scan. The scannable
  // pair is dark on light in both color modes.
  it('stays dark on light in dark mode, when the page turns', async () => {
    const screen = await render(QrCode, { props: { value: uri, label: 'QR' } })
    const svg = svgOf(screen.container)
    const light = { field: getComputedStyle(svg).backgroundColor, modules: getComputedStyle(svg).color }
    const lightPage = getComputedStyle(document.body).backgroundColor

    document.documentElement.classList.add('dark')

    await expect.poll(() => getComputedStyle(document.body).backgroundColor).not.toBe(lightPage)
    expect(getComputedStyle(svg).backgroundColor).toBe(light.field)
    expect(getComputedStyle(svg).color).toBe(light.modules)
    expect(light.field).toBe(lightPage)
    expect(light.field).not.toBe(light.modules)
  })

  it('redraws when the value changes', async () => {
    const screen = await render(QrCode, { props: { value: uri, label: 'QR' } })
    const before = svgOf(screen.container).querySelector('path')?.getAttribute('d')

    await screen.rerender({ value: `${uri}&digits=6` })

    expect(svgOf(screen.container).querySelector('path')?.getAttribute('d')).not.toBe(before)
  })
})
