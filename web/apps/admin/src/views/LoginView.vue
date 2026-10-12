<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, Card, CardContent, CardHeader, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'
import { ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import LoginLayout from '../layouts/LoginLayout.vue'
import { focusFirstInvalid, pick } from '../lib/form-errors'
import { safeRedirect } from '../lib/redirect'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// Input ergonomics only: the API decides what a valid login is.
const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
})

const { handleSubmit, setErrors, isSubmitting } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { email: '', password: '' },
})

const form = useTemplateRef<HTMLFormElement>('form')
const message = ref('')

const onSubmit = handleSubmit(
  async (values) => {
    message.value = ''
    const result = await auth.login(values)
    if (result.ok) {
      // The guard sends a session that still owes its second factor on from there.
      await router.replace(safeRedirect(route.query.redirect))
      return
    }
    setErrors(pick(result.errors, ['email', 'password']))
    message.value = result.message
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)
</script>

<template>
  <LoginLayout>
    <Card>
      <CardHeader>
        <h1
          tabindex="-1"
          class="text-lg font-semibold outline-none"
        >
          Log in
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
            name="email"
            label="Email"
          >
            <Input
              v-bind="field"
              type="email"
              autocomplete="username"
            />
          </FormField>
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
          <Button
            type="submit"
            :disabled="isSubmitting"
          >
            Log in
          </Button>
        </form>
      </CardContent>
    </Card>
  </LoginLayout>
</template>
