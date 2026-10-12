<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import AppLogo from '../components/AppLogo.vue'
</script>

<template>
  <main class="flex min-h-svh items-center justify-center p-4">
    <div class="flex w-full max-w-sm flex-col gap-6">
      <div class="flex justify-center">
        <AppLogo />
      </div>
      <slot />
    </div>
  </main>
</template>
