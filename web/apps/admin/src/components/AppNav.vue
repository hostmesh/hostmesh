<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { RouterLink } from 'vue-router'

// Whether a screen may be used is answered by the screen's own request
// (its `abilities`, or a 403): the API reports no abilities for navigation.
const links = [
  { to: '/', label: 'Dashboard' },
  { to: '/branding', label: 'Branding' },
]
</script>

<template>
  <RouterLink
    v-for="link in links"
    :key="link.to"
    :to="link.to"
    class="text-muted-foreground hover:text-foreground"
    exact-active-class="font-medium text-foreground"
  >
    {{ link.label }}
  </RouterLink>
</template>
