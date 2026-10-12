<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Toaster } from '@hostmesh/ui'
import { watchEffect } from 'vue'
import { RouterView } from 'vue-router'

import { useBrandingStore } from './stores/branding'

// Not awaited: the portal renders with the defaults until branding is there.
const store = useBrandingStore()
void store.load()

watchEffect(() => {
  document.title = store.branding.product_name
})
</script>

<template>
  <RouterView />
  <Toaster />
</template>
