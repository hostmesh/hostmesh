// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'

import { DEFAULT_BRANDING } from '../stores/branding'
import { brandingBody } from '../test/fixtures'
import { applyDocumentBranding } from './document-branding'

function icons(): HTMLLinkElement[] {
  return [...document.head.querySelectorAll<HTMLLinkElement>('link[rel="icon"]')]
}

afterEach(() => {
  document.title = ''
  icons().forEach((icon) => icon.remove())
})

describe('applyDocumentBranding', () => {
  it('names the page after the product', () => {
    applyDocumentBranding(document, brandingBody.data)

    expect(document.title).toBe('Acme Hosting')
  })

  it('adds the favicon', () => {
    applyDocumentBranding(document, brandingBody.data)

    expect(icons().map((icon) => icon.href)).toEqual([brandingBody.data.favicon])
  })

  it('replaces the favicon instead of adding a second one', () => {
    applyDocumentBranding(document, brandingBody.data)
    applyDocumentBranding(document, { ...brandingBody.data, favicon: 'https://api.hostmesh.test/api/v1/branding/images/favicon?v=ddd' })

    expect(icons().map((icon) => icon.href)).toEqual(['https://api.hostmesh.test/api/v1/branding/images/favicon?v=ddd'])
  })

  it('removes the favicon when none is configured', () => {
    applyDocumentBranding(document, brandingBody.data)
    applyDocumentBranding(document, DEFAULT_BRANDING)

    expect(icons()).toHaveLength(0)
    expect(document.title).toBe('Hostmesh')
  })
})
