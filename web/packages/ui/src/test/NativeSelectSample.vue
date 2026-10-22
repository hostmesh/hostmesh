<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { ref } from 'vue'

import { Field, FieldLabel, NativeSelect, NativeSelectOption } from '../index'

const status = ref('')
</script>

<template>
  <Field>
    <FieldLabel for="status">
      Status
    </FieldLabel>
    <NativeSelect
      id="status"
      v-model="status"
    >
      <NativeSelectOption value="">
        Any
      </NativeSelectOption>
      <NativeSelectOption value="active">
        Active
      </NativeSelectOption>
      <NativeSelectOption value="suspended">
        Suspended
      </NativeSelectOption>
    </NativeSelect>
    <output data-testid="chosen">{{ status }}</output>
  </Field>
</template>
