<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { ref, useId, useTemplateRef } from 'vue'

import { focusFirstInvalid, pick } from '../../lib/form-errors'
import type { Result } from '../../lib/result'
import type { PasswordAndCode } from '../../stores/auth'

// A change to two-factor that the API allows only with the current password
// and a valid code. `action` sends it; on a failure, this form shows why.
const props = withDefaults(
  defineProps<{
    title: string
    description: string
    submitLabel: string
    variant?: 'default' | 'destructive'
    action: (input: PasswordAndCode) => Promise<Result<unknown>>
  }>(),
  { variant: 'default' },
)

const headingId = useId()
const form = useTemplateRef<HTMLFormElement>('form')
const message = ref('')

// Input ergonomics only: the API decides what a valid password and code are.
const schema = z.object({
  password: z.string().min(1, 'Enter your password.'),
  code: z.string().trim().min(1, 'Enter the code from your authenticator app.').max(16, 'The code is too long.'),
})

const { handleSubmit, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { password: '', code: '' },
})

const onSubmit = handleSubmit(
  async (values) => {
    message.value = ''
    const result = await props.action(values)
    if (result.ok) {
      // Neither the password nor the code stays in the page.
      resetForm()
      return
    }
    setErrors(pick(result.errors, ['password', 'code'] as const))
    message.value = result.message
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)
</script>

<template>
  <form
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
      {{ props.title }}
    </h3>
    <p class="text-sm text-muted-foreground">
      {{ props.description }}
    </p>
    <p
      v-if="message"
      role="alert"
      class="text-sm text-destructive"
    >
      {{ message }}
    </p>
    <FormField
      v-slot="{ field }"
      name="password"
      label="Password"
    >
      <Input
        v-bind="field"
        type="password"
        autocomplete="current-password"
      />
    </FormField>
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
    <Button
      type="submit"
      class="self-start"
      :variant="props.variant"
      :disabled="isSubmitting"
    >
      {{ props.submitLabel }}
    </Button>
  </form>
</template>
