<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Toaster, useColorMode } from '@hostmesh/ui'
import { watchEffect } from 'vue'
import { RouterView } from 'vue-router'

import { applyBrandColors } from './lib/brand-colors'
import { applyDocumentBranding } from './lib/document-branding'
import { useBrandingStore } from './stores/branding'

// Not awaited: the portal renders with the defaults until branding is
// there, and keeps them when it cannot be loaded.
const store = useBrandingStore()
void store.load()

const { mode } = useColorMode()

// Runs again when branding arrives and when the color mode changes.
watchEffect(() => {
  applyDocumentBranding(document, store.branding)
  applyBrandColors(document.documentElement, store.branding.colors, mode.value)
})
</script>

<template>
  <RouterView />
  <Toaster />
</template>
