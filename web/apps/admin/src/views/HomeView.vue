<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import AppNav from '../components/AppNav.vue'
import AppLayout from '../layouts/AppLayout.vue'
</script>

<template>
  <AppLayout>
    <template #nav>
      <AppNav />
    </template>
    <h1
      tabindex="-1"
      class="text-2xl font-semibold outline-none"
    >
      Dashboard
    </h1>
  </AppLayout>
</template>
