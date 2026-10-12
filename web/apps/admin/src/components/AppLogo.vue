<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { useColorMode } from '@hostmesh/ui'
import { storeToRefs } from 'pinia'
import { computed } from 'vue'

import { useBrandingStore } from '../stores/branding'

// The logo of the current color mode, or the product name while that logo
// is not configured (spec section 28: null means "keep your default").
const { branding } = storeToRefs(useBrandingStore())
const { mode } = useColorMode()

const source = computed(() => branding.value.logos[mode.value])
</script>

<template>
  <img
    v-if="source"
    :src="source"
    :alt="branding.product_name"
    class="h-8 w-auto"
  >
  <span
    v-else
    class="text-lg font-semibold"
  >{{ branding.product_name }}</span>
</template>
