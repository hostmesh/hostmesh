// SPDX-License-Identifier: Apache-2.0
import { useColorMode as useVueUseColorMode } from '@vueuse/core'
import type { ComputedRef, Ref } from 'vue'

export type ColorMode = 'light' | 'dark'
export type ColorModePreference = ColorMode | 'auto'

/** The localStorage key that holds an explicit choice. */
export const COLOR_MODE_STORAGE_KEY = 'hostmesh-color-mode'

export interface UseColorMode {
  /** What the user chose. `auto`, the default, follows the system setting. */
  preference: Ref<ColorModePreference>
  /** What is applied: `<html>` has the class `dark` exactly when this is `dark`. */
  mode: ComputedRef<ColorMode>
}

/**
 * Applies light or dark mode to the document (spec section 29: the tokens
 * are redefined under `.dark`, and the default follows the system). Call it
 * once when the app starts.
 */
export function useColorMode(): UseColorMode {
  const { store, state } = useVueUseColorMode({
    storageKey: COLOR_MODE_STORAGE_KEY,
    // VueUse would switch transitions off with a <style> element it adds to
    // the document, which `style-src 'self'` blocks and reports (issue #95).
    disableTransition: false,
  })

  return { preference: store, mode: state }
}
