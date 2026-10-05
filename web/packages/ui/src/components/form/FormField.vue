<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Field as VeeField } from 'vee-validate'
import { useId } from 'vue'

import { Field, FieldDescription, FieldError, FieldLabel } from '#ui/components/ui/field'

// One field of a vee-validate form: label, control, description and errors,
// with the wiring that makes them accessible. The control is the default
// slot; bind its `field` prop to it:
//
//   <FormField v-slot="{ field }" name="email" label="Email">
//     <Input v-bind="field" type="email" />
//   </FormField>
const props = withDefaults(
  defineProps<{
    /** The field's path in the form values, as vee-validate names it. */
    name: string
    label: string
    description?: string
  }>(),
  // An explicit default: ESLint's vue/require-default-prop asks for one.
  { description: undefined },
)

const id = useId()
const descriptionId = `${id}-description`
const errorId = `${id}-error`

// The description first, then the error, as they appear on screen.
function describedBy(invalid: boolean): string | undefined {
  const ids = [props.description ? descriptionId : undefined, invalid ? errorId : undefined].filter(
    (value) => value !== undefined,
  )
  return ids.length > 0 ? ids.join(' ') : undefined
}
</script>

<template>
  <VeeField
    v-slot="{ componentField, errors }"
    :name="props.name"
  >
    <Field :data-invalid="errors.length > 0 ? 'true' : undefined">
      <FieldLabel :for="id">
        {{ props.label }}
      </FieldLabel>
      <slot
        :field="{
          ...componentField,
          'id': id,
          'aria-invalid': errors.length > 0 ? true : undefined,
          'aria-describedby': describedBy(errors.length > 0),
        }"
      />
      <FieldDescription
        v-if="props.description"
        :id="descriptionId"
      >
        {{ props.description }}
      </FieldDescription>
      <FieldError
        v-if="errors.length > 0"
        :id="errorId"
        :errors="errors"
      />
    </Field>
  </VeeField>
</template>
