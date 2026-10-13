// SPDX-License-Identifier: Apache-2.0
import { mount } from '@vue/test-utils'
import { encode } from 'uqr'
import { describe, expect, it } from 'vitest'

import QrCode from './QrCode.vue'

const uri = 'otpauth://totp/Hostmesh:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Hostmesh'

describe('QrCode', () => {
  it('is an image with a text alternative', () => {
    const wrapper = mount(QrCode, { props: { value: uri, label: 'QR code for your authenticator app' } })

    const svg = wrapper.get('svg')
    expect(svg.attributes('role')).toBe('img')
    expect(svg.attributes('aria-label')).toBe('QR code for your authenticator app')
  })

  it('draws one square for every dark module, with a quiet zone', () => {
    const wrapper = mount(QrCode, { props: { value: uri, label: 'QR' } })
    const { data, size } = encode(uri, { border: 4 })
    const dark = data.flat().filter(Boolean).length

    expect(wrapper.get('svg').attributes('viewBox')).toBe(`0 0 ${size} ${size}`)
    expect(wrapper.get('path').attributes('d')?.match(/M/g)).toHaveLength(dark)
    // The top-left finder pattern starts right after the quiet zone.
    expect(wrapper.get('path').attributes('d')?.startsWith('M4 4h1v1h-1z')).toBe(true)
  })

  it('takes its colors from the tokens and has no inline style', () => {
    const wrapper = mount(QrCode, { props: { value: uri, label: 'QR' } })

    expect(wrapper.html()).not.toContain('style=')
    expect(wrapper.html()).not.toMatch(/#[0-9a-f]{3,6}\b/i)
    expect(wrapper.get('path').attributes('fill')).toBe('currentColor')
  })

  // Issue #124: light modules on a dark field do not scan. The scannable
  // pair of @hostmesh/ui is dark on light in both color modes.
  it('is drawn with the scannable pair, which does not follow the color mode', () => {
    const wrapper = mount(QrCode, { props: { value: uri, label: 'QR' } })

    const classes = wrapper.get('svg').classes()
    expect(classes).toContain('bg-scannable')
    expect(classes).toContain('text-scannable-foreground')
    expect(classes).not.toContain('bg-background')
    expect(classes).not.toContain('text-foreground')
  })

  it('redraws when the value changes', async () => {
    const wrapper = mount(QrCode, { props: { value: uri, label: 'QR' } })
    const before = wrapper.get('path').attributes('d')

    await wrapper.setProps({ value: `${uri}&digits=6` })

    expect(wrapper.get('path').attributes('d')).not.toBe(before)
  })
})
