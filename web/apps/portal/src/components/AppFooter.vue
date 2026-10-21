<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { storeToRefs } from 'pinia'
import { computed } from 'vue'

import { useBrandingStore } from '../stores/branding'

// The support and legal links of branding (spec section 28). A link that is
// not set is left out, and without any link there is no footer at all.
const { branding } = storeToRefs(useBrandingStore())

const labels = {
  support: 'Support',
  terms: 'Terms of service',
  privacy: 'Privacy policy',
} as const

const links = computed(() =>
  (Object.keys(labels) as (keyof typeof labels)[]).flatMap((key) => {
    const href = branding.value.links[key]
    return href ? [{ key, href, label: labels[key] }] : []
  }),
)
</script>

<template>
  <footer
    v-if="links.length > 0"
    class="flex flex-wrap justify-center gap-x-6 gap-y-2 px-6 py-4 text-sm text-muted-foreground"
  >
    <a
      v-for="link in links"
      :key="link.key"
      :href="link.href"
      class="underline-offset-4 hover:text-foreground hover:underline"
    >{{ link.label }}</a>
  </footer>
</template>
