<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Toaster } from '@hostmesh/ui'
import { watchEffect } from 'vue'
import { RouterView } from 'vue-router'

import { useBrandingStore } from './stores/branding'

// Spec section 29: the admin app takes the product name and the logo from
// branding, and nothing else. Not awaited: the app renders with the
// defaults until the answer is there.
const store = useBrandingStore()
void store.load()

watchEffect(() => {
  document.title = `${store.branding.product_name} admin`
})
</script>

<template>
  <RouterView />
  <Toaster />
</template>
