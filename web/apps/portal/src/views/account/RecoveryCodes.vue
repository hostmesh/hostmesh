<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button } from '@hostmesh/ui'
import { onMounted, useTemplateRef } from 'vue'

const props = defineProps<{ codes: string[] }>()
const emit = defineEmits<{ saved: [] }>()

const heading = useTemplateRef<HTMLHeadingElement>('heading')

// The section changed under the customer: say so by moving to its heading.
onMounted(() => {
  heading.value?.focus()
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <h3
      ref="heading"
      tabindex="-1"
      data-test="recovery-codes-heading"
      class="font-semibold outline-none"
    >
      Save your recovery codes
    </h3>
    <p class="text-sm text-muted-foreground">
      Each code logs you in once if you lose your authenticator app. They are shown only now: store them somewhere safe.
    </p>
    <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
      <li
        v-for="code in props.codes"
        :key="code"
        data-test="recovery-code"
      >
        {{ code }}
      </li>
    </ul>
    <Button
      type="button"
      class="self-start"
      @click="emit('saved')"
    >
      I have saved these codes
    </Button>
  </div>
</template>
