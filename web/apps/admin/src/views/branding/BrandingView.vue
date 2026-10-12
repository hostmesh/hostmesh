<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button } from '@hostmesh/ui'
import { onMounted, ref } from 'vue'

import { api } from '../../api'
import AppNav from '../../components/AppNav.vue'
import AppLayout from '../../layouts/AppLayout.vue'
import { useBrandingStore } from '../../stores/branding'
import BrandingForm from './BrandingForm.vue'
import type { BrandingResource } from './schema'

// Spec section 28: an admin screen edits branding.
const publicBranding = useBrandingStore()

const state = ref<'loading' | 'ready' | 'forbidden' | 'failed'>('loading')
const branding = ref<BrandingResource | null>(null)

async function load(): Promise<void> {
  state.value = 'loading'
  try {
    const { data, response } = await api.GET('/admin/branding')
    if (data) {
      branding.value = data.data
      state.value = 'ready'
    } else {
      state.value = response.status === 403 ? 'forbidden' : 'failed'
    }
  } catch {
    state.value = 'failed'
  }
}

// The response of a save is the new branding: show it everywhere at once.
// The public endpoint is cached for five minutes, so it is not asked again.
function onChanged(next: BrandingResource): void {
  branding.value = next
  publicBranding.apply(next)
}

onMounted(load)
</script>

<template>
  <AppLayout>
    <template #nav>
      <AppNav />
    </template>
    <div class="flex flex-col gap-6">
      <h1
        tabindex="-1"
        class="text-2xl font-semibold outline-none"
      >
        Branding
      </h1>
      <p
        v-if="state === 'loading'"
        class="text-sm text-muted-foreground"
      >
        Loading…
      </p>
      <p
        v-else-if="state === 'forbidden'"
        role="alert"
        class="text-sm"
      >
        You do not have permission to view branding.
      </p>
      <div
        v-else-if="state === 'failed'"
        role="alert"
        class="flex flex-col items-start gap-2 text-sm"
      >
        <p>Branding could not be loaded.</p>
        <Button
          type="button"
          variant="outline"
          data-test="retry"
          @click="load"
        >
          Try again
        </Button>
      </div>
      <BrandingForm
        v-else-if="branding"
        :branding="branding"
        @saved="onChanged"
      />
    </div>
  </AppLayout>
</template>
