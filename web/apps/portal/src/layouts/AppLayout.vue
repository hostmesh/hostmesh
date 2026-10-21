<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, toast } from '@hostmesh/ui'
import { useRouter } from 'vue-router'

import AppFooter from '../components/AppFooter.vue'
import AppLogo from '../components/AppLogo.vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logOut(): Promise<void> {
  const result = await auth.logout()
  if (result.ok) {
    await router.replace('/login')
  } else {
    toast.error(result.message)
  }
}
</script>

<template>
  <div class="flex min-h-svh flex-col">
    <header class="flex items-center gap-6 border-b px-6 py-3">
      <AppLogo />
      <nav
        aria-label="Main"
        class="flex flex-1 items-center gap-4 text-sm"
      >
        <slot name="nav" />
      </nav>
      <span class="text-sm text-muted-foreground">{{ auth.user?.name }}</span>
      <Button
        variant="outline"
        @click="logOut"
      >
        Log out
      </Button>
    </header>
    <main class="flex-1 p-6">
      <slot />
    </main>
    <AppFooter class="border-t" />
  </div>
</template>
