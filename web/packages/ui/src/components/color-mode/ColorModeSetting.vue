<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { useId } from 'vue'

import { Field, FieldDescription, FieldLabel } from '#ui/components/ui/field'
import { NativeSelect, NativeSelectOption } from '#ui/components/ui/native-select'
import { useColorMode, type ColorModePreference } from '#ui/composables/color-mode'

// Light, dark or the system's setting, for this browser. Every
// caller of useColorMode() follows at once, and the early script applies
// the choice before the first paint of the next load.
const { preference } = useColorMode()

const choices: { value: ColorModePreference; label: string }[] = [
  { value: 'auto', label: 'System' },
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
]

const id = useId()
</script>

<template>
  <Field>
    <FieldLabel :for="id">
      Appearance
    </FieldLabel>
    <NativeSelect
      :id="id"
      v-model="preference"
      :aria-describedby="`${id}-description`"
    >
      <NativeSelectOption
        v-for="choice in choices"
        :key="choice.value"
        :value="choice.value"
      >
        {{ choice.label }}
      </NativeSelectOption>
    </NativeSelect>
    <FieldDescription :id="`${id}-description`">
      Remembered in this browser only.
    </FieldDescription>
  </Field>
</template>
