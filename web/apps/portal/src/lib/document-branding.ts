// SPDX-License-Identifier: Apache-2.0
import type { Branding } from '../stores/branding'

/** The page title and the favicon from branding. Without a favicon the browser's default applies. */
export function applyDocumentBranding(doc: Document, branding: Branding): void {
  doc.title = branding.product_name

  let icon = doc.head.querySelector<HTMLLinkElement>('link[rel="icon"]')
  if (branding.favicon === null) {
    icon?.remove()
    return
  }
  if (icon === null) {
    icon = doc.createElement('link')
    icon.rel = 'icon'
    doc.head.append(icon)
  }
  icon.href = branding.favicon
}
