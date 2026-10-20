<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, FormField, Input, QrCode, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { nextTick, ref, useId, useTemplateRef } from 'vue'

import { focusFirstInvalid, pick } from '../../lib/form-errors'
import type { Result } from '../../lib/result'
import { useAuthStore, type Enrollment } from '../../stores/auth'

// Turning two-factor on: a new secret as a QR code and a key, then a code
// from the app to confirm it. `confirm` sends the code; on a failure, this
// form shows why.
const props = defineProps<{
  confirm: (code: string) => Promise<Result<unknown>>
}>()

const auth = useAuthStore()
const headingId = useId()
const enrollment = ref<Enrollment | null>(null)
const message = ref('')
const form = useTemplateRef<HTMLFormElement>('form')

const schema = z.object({
  code: z.string().trim().min(1, 'Enter the code from your authenticator app.').max(16, 'The code is too long.'),
})

const { handleSubmit, setErrors, isSubmitting } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { code: '' },
})

async function start(): Promise<void> {
  message.value = ''
  const result = await auth.startEnrollment()
  if (!result.ok) {
    message.value = result.message
    return
  }
  enrollment.value = result.value
  await nextTick()
  form.value?.querySelector<HTMLInputElement>('input')?.focus()
}

function cancel(): void {
  // The unconfirmed secret stays on the server until the next start replaces it.
  enrollment.value = null
  message.value = ''
}

const onSubmit = handleSubmit(
  async ({ code }) => {
    message.value = ''
    const result = await props.confirm(code)
    if (result.ok) {
      return
    }
    setErrors(pick(result.errors, ['code'] as const))
    message.value = result.message
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)
</script>

<template>
  <div class="flex flex-col gap-4">
    <p
      v-if="message"
      role="alert"
      class="text-sm text-destructive"
    >
      {{ message }}
    </p>
    <form
      v-if="enrollment"
      ref="form"
      novalidate
      :aria-labelledby="headingId"
      class="flex flex-col gap-4 rounded-lg border p-4"
      @submit="onSubmit"
    >
      <h3
        :id="headingId"
        class="font-semibold"
      >
        Set up two-factor authentication
      </h3>
      <p class="text-sm text-muted-foreground">
        Scan the code with an authenticator app, or enter the key by hand, then enter the 6-digit code the app shows.
      </p>
      <div class="flex justify-center">
        <QrCode
          :value="enrollment.provisioning_uri"
          label="QR code to scan with your authenticator app"
        />
      </div>
      <p class="text-sm">
        Key:
        <code
          data-test="secret"
          class="font-mono break-all"
        >{{ enrollment.secret }}</code>
      </p>
      <FormField
        v-slot="{ field }"
        name="code"
        label="Authentication code"
      >
        <Input
          v-bind="field"
          autocomplete="one-time-code"
          inputmode="numeric"
        />
      </FormField>
      <div class="flex gap-2">
        <Button
          type="submit"
          :disabled="isSubmitting"
        >
          Turn on
        </Button>
        <Button
          type="button"
          variant="ghost"
          @click="cancel"
        >
          Cancel
        </Button>
      </div>
    </form>
    <template v-else>
      <p class="text-sm">
        Two-factor authentication is off. With it on, logging in needs a code from an authenticator app as well as your password.
      </p>
      <Button
        type="button"
        class="self-start"
        @click="start"
      >
        Turn on two-factor authentication
      </Button>
    </template>
  </div>
</template>
