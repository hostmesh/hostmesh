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
import BrandingImageSlot from './BrandingImageSlot.vue'

// The limits are the API's (App\Domain\Branding\ImageSlot); here they are only told to the user.
const LOGO_HINT = 'PNG, JPEG or WebP, at most 1 MB and 2000 by 2000 pixels.'
const imageSlots = [
  { name: 'logo-light', label: 'Logo, light mode', hint: LOGO_HINT },
  { name: 'logo-dark', label: 'Logo, dark mode', hint: LOGO_HINT },
  { name: 'favicon', label: 'Favicon', hint: 'A square PNG, at most 256 KB and 512 by 512 pixels.' },
] as const

function imageUrl(current: BrandingResource, name: (typeof imageSlots)[number]['name']): string | null {
  if (name === 'favicon') {
    return current.favicon
  }
  return name === 'logo-light' ? current.logos.light : current.logos.dark
}

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
      <template v-else-if="branding">
        <BrandingForm
          :branding="branding"
          @saved="onChanged"
        />
        <section
          aria-labelledby="branding-images"
          class="flex max-w-xl flex-col gap-6"
        >
          <h2
            id="branding-images"
            class="text-lg font-semibold"
          >
            Images
          </h2>
          <BrandingImageSlot
            v-for="slot in imageSlots"
            :key="slot.name"
            :name="slot.name"
            :label="slot.label"
            :hint="slot.hint"
            :url="imageUrl(branding, slot.name)"
            :can-update="branding.abilities.update"
            @changed="onChanged"
          />
        </section>
      </template>
    </div>
  </AppLayout>
</template>
