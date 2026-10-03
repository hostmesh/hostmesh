<!-- SPDX-License-Identifier: Apache-2.0 -->
<!-- An example for the tests only. It is not exported by the package. -->
<script setup lang="ts">
import { useForm } from 'vee-validate'
import * as z from 'zod'

import { Button } from '#ui/components/ui/button'
import { Input } from '#ui/components/ui/input'

import FormField from '../FormField.vue'
import { toTypedSchema } from '../typed-schema'

const emit = defineEmits<{
  submit: [values: { domain: string, email: string }]
}>()

const schema = z.object({
  domain: z.string().regex(/^[a-z0-9-]+(\.[a-z0-9-]+)+$/, 'Enter a domain such as example.com.'),
  email: z.email('Enter a valid email address.'),
})

const { handleSubmit } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { domain: '', email: '' },
})

const onSubmit = handleSubmit((values) => {
  emit('submit', values)
})
</script>

<template>
  <form
    novalidate
    @submit="onSubmit"
  >
    <FormField
      v-slot="{ field }"
      name="domain"
      label="Domain"
      description="Without the protocol."
    >
      <Input
        v-bind="field"
        autocomplete="off"
      />
    </FormField>
    <FormField
      v-slot="{ field }"
      name="email"
      label="Email"
    >
      <Input
        v-bind="field"
        type="email"
      />
    </FormField>
    <Button type="submit">
      Create website
    </Button>
  </form>
</template>
