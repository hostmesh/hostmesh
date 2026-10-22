<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { ref, useId, useTemplateRef } from 'vue'

import { focusFirstInvalid, pick } from '../../lib/form-errors'
import { useAuthStore } from '../../stores/auth'

// Issue #133, decision 0023: the account's own password, with the current
// one, and a code where two-factor is on. The API decides what a valid new
// password is (15 characters to 72 bytes, none known from breaches); here
// it is only told to the user.
const props = defineProps<{
  needsCode: boolean
}>()

const auth = useAuthStore()
const headingId = useId()
const form = useTemplateRef<HTMLFormElement>('form')
const message = ref('')
const changed = ref(false)

// Input ergonomics only: nothing is sent with an empty field.
const schema = z.object({
  current_password: z.string().min(1, 'Enter your current password.'),
  new_password: z.string().min(1, 'Enter a new password.'),
  code: props.needsCode
    ? z.string().trim().min(1, 'Enter the code from your authenticator app.').max(16, 'The code is too long.')
    : z.string().optional(),
})

const { handleSubmit, setErrors, resetForm, isSubmitting } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { current_password: '', new_password: '', code: '' },
})

const onSubmit = handleSubmit(
  async (values) => {
    message.value = ''
    changed.value = false
    const result = await auth.changePassword(
      props.needsCode
        ? values
        : { current_password: values.current_password, new_password: values.new_password },
    )
    if (result.ok) {
      // No password stays in the page.
      resetForm()
      changed.value = true
      return
    }
    setErrors(pick(result.errors, ['current_password', 'new_password', 'code'] as const))
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
      Change password
    </h3>
    <p class="text-sm text-muted-foreground">
      Your other sessions are signed out. This one stays signed in.
    </p>
    <p
      v-if="changed"
      role="status"
      class="text-sm"
    >
      Your password was changed. Your other sessions were signed out.
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
      name="current_password"
      label="Current password"
    >
      <Input
        v-bind="field"
        type="password"
        autocomplete="current-password"
      />
    </FormField>
    <FormField
      v-slot="{ field }"
      name="new_password"
      label="New password"
      description="At least 15 characters. Any characters; a few words make a good one."
    >
      <Input
        v-bind="field"
        type="password"
        autocomplete="new-password"
      />
    </FormField>
    <FormField
      v-if="props.needsCode"
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
      :disabled="isSubmitting"
    >
      Change password
    </Button>
  </form>
</template>
