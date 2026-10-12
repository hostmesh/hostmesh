// SPDX-License-Identifier: Apache-2.0
import type { paths } from '@hostmesh/api-client'
import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '../api'

/** The public branding of the installation (spec section 28). */
export type Branding = paths['/branding']['get']['responses'][200]['content']['application/json']['data']

/** What the app shows until branding has loaded, and when it cannot be loaded. */
export const DEFAULT_BRANDING: Branding = {
  product_name: 'Hostmesh',
  logos: { light: null, dark: null },
  favicon: null,
  colors: {
    light: { primary: null, primary_foreground: null },
    dark: { primary: null, primary_foreground: null },
  },
  links: { support: null, terms: null, privacy: null },
}

export const useBrandingStore = defineStore('branding', () => {
  const branding = ref<Branding>(DEFAULT_BRANDING)

  /** Loads `GET /branding`. A failure keeps what is there: branding never blocks the app. */
  async function load(): Promise<void> {
    try {
      const { data } = await api.GET('/branding')
      if (data) {
        branding.value = data.data
      }
    } catch {
      // The defaults stay.
    }
  }

  /** Takes a branding that is already known, without a request. */
  function apply(next: Branding): void {
    branding.value = next
  }

  return { branding, load, apply }
})
