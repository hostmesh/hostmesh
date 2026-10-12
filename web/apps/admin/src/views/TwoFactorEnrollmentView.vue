<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, Card, CardContent, CardHeader, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { nextTick, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import QrCode from '../components/QrCode.vue'
import LoginLayout from '../layouts/LoginLayout.vue'
import { focusFirstInvalid } from '../lib/form-errors'
import { safeRedirect } from '../lib/redirect'
import { useAuthStore, type Enrollment } from '../stores/auth'

// Spec section 28: admin requires two-factor authentication. An
// administrator without it lands here after the password and can do
// nothing else until it is set up.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const enrollment = ref<Enrollment | null>(null)
const recoveryCodes = ref<string[] | null>(null)
const message = ref('')
const form = useTemplateRef<HTMLFormElement>('form')
const heading = useTemplateRef<HTMLHeadingElement>('heading')

const schema = z.object({
  code: z.string().trim().min(1, 'Enter the code from your authenticator app.').max(16, 'The code is too long.'),
})

const { handleSubmit, setErrors, isSubmitting } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { code: '' },
})

onMounted(async () => {
  const result = await auth.startEnrollment()
  if (result.ok) {
    enrollment.value = result.value
  } else {
    message.value = result.message
  }
})

const onSubmit = handleSubmit(
  async ({ code }) => {
    message.value = ''
    const result = await auth.confirmEnrollment(code)
    if (result.ok) {
      recoveryCodes.value = result.value
      // The screen changed under the user: say so by moving to its heading.
      await nextTick()
      heading.value?.focus()
      return
    }
    if (result.errors.code !== undefined) {
      setErrors({ code: result.errors.code })
    }
    message.value = result.message
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)

async function finish(): Promise<void> {
  await auth.finishEnrollment()
  await router.replace(safeRedirect(route.query.redirect))
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
          ref="heading"
          tabindex="-1"
          class="text-lg font-semibold outline-none"
        >
          {{ recoveryCodes ? 'Save your recovery codes' : 'Set up two-factor authentication' }}
        </h1>
      </CardHeader>
      <CardContent
        v-if="recoveryCodes"
        class="flex flex-col gap-4"
      >
        <p class="text-sm text-muted-foreground">
          Each code logs you in once if you lose your authenticator app. They are shown only now: store them somewhere safe.
        </p>
        <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
          <li
            v-for="recoveryCode in recoveryCodes"
            :key="recoveryCode"
            data-test="recovery-code"
          >
            {{ recoveryCode }}
          </li>
        </ul>
        <Button
          type="button"
          data-test="finish"
          @click="finish"
        >
          I have saved these codes
        </Button>
      </CardContent>
      <CardContent
        v-else
        class="flex flex-col gap-4"
      >
        <p
          v-if="message"
          role="alert"
          class="text-sm text-destructive"
        >
          {{ message }}
        </p>
        <template v-if="enrollment">
          <p class="text-sm text-muted-foreground">
            Administrators must use two-factor authentication. Scan the code with an authenticator app, or enter the key by hand, then enter the 6-digit code the app shows.
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
          <form
            ref="form"
            novalidate
            class="flex flex-col gap-4"
            @submit="onSubmit"
          >
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
              :disabled="isSubmitting"
            >
              Turn on two-factor authentication
            </Button>
          </form>
        </template>
        <Button
          type="button"
          variant="ghost"
          data-test="cancel"
          @click="cancel"
        >
          Log out
        </Button>
      </CardContent>
    </Card>
  </LoginLayout>
</template>
