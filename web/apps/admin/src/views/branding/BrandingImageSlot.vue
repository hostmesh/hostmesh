<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import type { components } from '@hostmesh/api-client'
import { Button, Label } from '@hostmesh/ui'
import { ref, useId } from 'vue'

import { api } from '../../api'
import { imageBody } from '../../lib/image-body'
import { failure, UNREACHABLE, type Failure } from '../../lib/result'
import type { BrandingResource } from './schema'

// One image of branding: a logo or the favicon. Choosing a file uploads
// it at once. The API reads the type from the bytes and decides whether
// the image is acceptable (spec section 28); `hint` only tells the user.
const props = defineProps<{
  name: components['schemas']['ImageSlot']
  label: string
  hint: string
  url: string | null
  canUpdate: boolean
}>()
const emit = defineEmits<{ changed: [branding: BrandingResource] }>()

const id = useId()
const hintId = `${id}-hint`
const errorId = `${id}-error`

const error = ref('')
const busy = ref(false)

function show(result: Failure): void {
  error.value = result.errors.file?.join(' ') ?? result.message
}

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (file === undefined) {
    return
  }
  error.value = ''
  busy.value = true
  try {
    const { data, error: problem, response } = await api.POST('/admin/branding/images/{slot}', {
      params: { path: { slot: props.name } },
      // The schema types the multipart field as a string; the serializer sends the file itself.
      body: { file: file.name },
      bodySerializer: () => imageBody(file),
    })
    if (data) {
      emit('changed', data.data)
    } else {
      show(failure(response, problem))
    }
  } catch {
    error.value = UNREACHABLE
  } finally {
    busy.value = false
    // So that choosing the same file again fires `change`.
    input.value = ''
  }
}

async function remove(): Promise<void> {
  error.value = ''
  busy.value = true
  try {
    const { data, error: problem, response } = await api.DELETE('/admin/branding/images/{slot}', {
      params: { path: { slot: props.name } },
    })
    if (data) {
      emit('changed', data.data)
    } else {
      show(failure(response, problem))
    }
  } catch {
    error.value = UNREACHABLE
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <Label :for="canUpdate ? id : undefined">{{ props.label }}</Label>
    <img
      v-if="props.url"
      :src="props.url"
      :alt="`Current image: ${props.label}`"
      class="h-12 w-auto self-start rounded-md border bg-muted p-1"
    >
    <p
      v-else
      class="text-sm text-muted-foreground"
    >
      No image. The default is used.
    </p>
    <template v-if="canUpdate">
      <input
        :id="id"
        type="file"
        accept="image/png,image/jpeg,image/webp"
        class="text-sm file:mr-3 file:rounded-md file:border file:border-border file:bg-background file:px-2.5 file:py-1 file:text-sm file:font-medium file:text-foreground"
        :disabled="busy"
        :aria-invalid="error ? true : undefined"
        :aria-describedby="error ? `${hintId} ${errorId}` : hintId"
        @change="upload"
      >
      <p
        :id="hintId"
        class="text-sm text-muted-foreground"
      >
        {{ props.hint }}
      </p>
      <p
        v-if="error"
        :id="errorId"
        role="alert"
        class="text-sm text-destructive"
      >
        {{ error }}
      </p>
      <Button
        v-if="props.url"
        type="button"
        variant="destructive"
        class="self-start"
        data-test="remove"
        :disabled="busy"
        @click="remove"
      >
        Remove
      </Button>
    </template>
  </div>
</template>
