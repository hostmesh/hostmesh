<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, FormField, Input, toast, toTypedSchema, useForm } from '@hostmesh/ui'
import { computed, ref, useTemplateRef } from 'vue'

import { api } from '../../api'
import { focusFirstInvalid, pick } from '../../lib/form-errors'
import { failure, UNREACHABLE } from '../../lib/result'
import {
  BRANDING_FIELDS,
  brandingSchema,
  describeWarning,
  toFormValues,
  toRequest,
  type BrandingResource,
} from './schema'

const props = defineProps<{ branding: BrandingResource }>()
const emit = defineEmits<{ saved: [branding: BrandingResource] }>()

// What the administrator may do comes from the API (spec section 28).
const canUpdate = computed(() => props.branding.abilities.update)

const { handleSubmit, setErrors, isSubmitting, resetForm } = useForm({
  validationSchema: toTypedSchema(brandingSchema),
  initialValues: toFormValues(props.branding),
})

const form = useTemplateRef<HTMLFormElement>('form')
const message = ref('')

const colorFields = [
  { name: 'colors.light.primary', label: 'Primary color, light mode' },
  { name: 'colors.light.primary_foreground', label: 'Text on primary, light mode' },
  { name: 'colors.dark.primary', label: 'Primary color, dark mode' },
  { name: 'colors.dark.primary_foreground', label: 'Text on primary, dark mode' },
] as const

const linkFields = [
  { name: 'links.support', label: 'Support link' },
  { name: 'links.terms', label: 'Terms of service link' },
  { name: 'links.privacy', label: 'Privacy policy link' },
] as const

const onSubmit = handleSubmit(
  async (values) => {
    message.value = ''
    try {
      const { data, error, response } = await api.PUT('/admin/branding', { body: toRequest(values) })
      if (data) {
        resetForm({ values: toFormValues(data.data) })
        emit('saved', data.data)
        toast.success('Branding saved.')
        return
      }
      const result = failure(response, error)
      setErrors(pick(result.errors, BRANDING_FIELDS))
      message.value = result.message
    } catch {
      message.value = UNREACHABLE
    }
    await focusFirstInvalid(form.value)
  },
  () => focusFirstInvalid(form.value),
)
</script>

<template>
  <form
    ref="form"
    novalidate
    class="flex max-w-xl flex-col gap-6"
    @submit="onSubmit"
  >
    <p
      v-if="message"
      role="alert"
      class="text-sm text-destructive"
    >
      {{ message }}
    </p>

    <!-- A live region that is always there, so that a warning that appears after a save is announced. -->
    <div
      role="status"
      class="text-sm"
    >
      <template v-if="props.branding.warnings.length > 0">
        <p class="font-medium">
          These colors are saved, but they may be hard to read:
        </p>
        <ul class="ml-4 list-disc text-muted-foreground">
          <li
            v-for="warning in props.branding.warnings"
            :key="`${warning.mode}-${warning.color}-${warning.against}`"
          >
            {{ describeWarning(warning) }}
          </li>
        </ul>
      </template>
    </div>

    <fieldset
      :disabled="!canUpdate"
      class="flex flex-col gap-4"
    >
      <legend class="sr-only">
        Branding
      </legend>
      <FormField
        v-slot="{ field }"
        name="product_name"
        label="Product name"
        description="Shown in page titles, in the header and in emails. At most 60 characters."
      >
        <Input
          v-bind="field"
          autocomplete="off"
        />
      </FormField>
      <FormField
        v-for="color in colorFields"
        :key="color.name"
        v-slot="{ field }"
        :name="color.name"
        :label="color.label"
        description="#rrggbb. Leave empty to use the default."
      >
        <Input
          v-bind="field"
          autocomplete="off"
          spellcheck="false"
        />
      </FormField>
      <FormField
        v-for="link in linkFields"
        :key="link.name"
        v-slot="{ field }"
        :name="link.name"
        :label="link.label"
        description="An https:// address. Leave empty for none."
      >
        <Input
          v-bind="field"
          type="url"
          autocomplete="off"
        />
      </FormField>
    </fieldset>

    <Button
      v-if="canUpdate"
      type="submit"
      class="self-start"
      :disabled="isSubmitting"
    >
      Save branding
    </Button>
  </form>
</template>
