<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import AppNav from '../../components/AppNav.vue'
import AppLayout from '../../layouts/AppLayout.vue'
import { useAuthStore } from '../../stores/auth'
import TwoFactorSection from './TwoFactorSection.vue'

// Issue #139: the customer's own account. The router lets only a complete
// session in, so the user is there.
const auth = useAuthStore()
</script>

<template>
  <AppLayout>
    <template #nav>
      <AppNav />
    </template>
    <div class="flex max-w-xl flex-col gap-8">
      <h1
        tabindex="-1"
        class="text-2xl font-semibold outline-none"
      >
        Account
      </h1>
      <section
        aria-labelledby="profile-heading"
        class="flex flex-col gap-4"
      >
        <h2
          id="profile-heading"
          class="text-lg font-semibold"
        >
          Profile
        </h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt class="text-muted-foreground">
            Name
          </dt>
          <dd data-test="name">
            {{ auth.user?.name }}
          </dd>
          <dt class="text-muted-foreground">
            Email
          </dt>
          <dd data-test="email">
            {{ auth.user?.email }}
          </dd>
        </dl>
      </section>
      <TwoFactorSection v-if="auth.user?.abilities.manage_two_factor" />
    </div>
  </AppLayout>
</template>
