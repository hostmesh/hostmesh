<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import AppFooter from '../components/AppFooter.vue'
import AppLogo from '../components/AppLogo.vue'
</script>

<template>
  <div class="flex min-h-svh flex-col">
    <main class="flex flex-1 items-center justify-center p-4">
      <div class="flex w-full max-w-sm flex-col gap-6">
        <div class="flex justify-center">
          <AppLogo />
        </div>
        <slot />
      </div>
    </main>
    <AppFooter />
  </div>
</template>
