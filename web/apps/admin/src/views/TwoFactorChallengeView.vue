<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, Card, CardContent, CardHeader, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import LoginLayout from '../layouts/LoginLayout.vue'
import { focusFirstInvalid } from '../lib/form-errors'
import { safeRedirect } from '../lib/redirect'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// One field for either kind of code. The API decides whether it is valid.
const schema = z.object({
  code: z.string().trim().min(1, 'Enter a code.').max(64, 'The code is too long.'),
})

const { handleSubmit, setErrors, isSubmitting, resetForm } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { code: '' },
})

const form = useTemplateRef<HTMLFormElement>('form')
const message = ref('')
const useRecoveryCode = ref(false)

const onSubmit = handleSubmit(
  async ({ code }) => {
    message.value = ''
    const result = await auth.completeChallenge(useRecoveryCode.value ? { recovery_code: code } : { code })
    if (result.ok) {
      await router.replace(safeRedirect(route.query.redirect))
      return
    }
    const messages = result.errors.code ?? result.errors.recovery_code
    if (messages !== undefined) {
      setErrors({ code: messages })
    }
    message.value = result.message
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)

function toggle(): void {
  useRecoveryCode.value = !useRecoveryCode.value
  message.value = ''
  resetForm()
}

async function cancel(): Promise<void> {
  const result = await auth.logout()
  if (result.ok) {
    await router.replace('/login')
  } else {
    message.value = result.message
  }
}
</script>

<template>
  <LoginLayout>
    <Card>
      <CardHeader>
        <h1
          tabindex="-1"
          class="text-lg font-semibold outline-none"
        >
          Two-factor authentication
        </h1>
      </CardHeader>
      <CardContent>
        <form
          ref="form"
          novalidate
          class="flex flex-col gap-4"
          @submit="onSubmit"
        >
          <p
            v-if="message"
            role="alert"
            class="text-sm text-destructive"
          >
            {{ message }}
          </p>
          <FormField
            v-slot="{ field }"
            name="code"
            :label="useRecoveryCode ? 'Recovery code' : 'Authentication code'"
            :description="useRecoveryCode
              ? 'One of the codes you saved when you set up two-factor authentication. Each works once.'
              : 'The 6-digit code from your authenticator app.'"
          >
            <Input
              v-bind="field"
              autocomplete="one-time-code"
              :inputmode="useRecoveryCode ? 'text' : 'numeric'"
            />
          </FormField>
          <Button
            type="submit"
            :disabled="isSubmitting"
          >
            Continue
          </Button>
          <Button
            type="button"
            variant="link"
            data-test="toggle"
            @click="toggle"
          >
            {{ useRecoveryCode ? 'Use an authentication code' : 'Use a recovery code' }}
          </Button>
          <Button
            type="button"
            variant="ghost"
            data-test="cancel"
            @click="cancel"
          >
            Log in as someone else
          </Button>
        </form>
      </CardContent>
    </Card>
  </LoginLayout>
</template>
