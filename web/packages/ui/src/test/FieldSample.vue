<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { ref } from 'vue'

import { Field, FieldDescription, FieldLabel, Input } from '../index'

const domain = ref('')
</script>

<template>
  <Field>
    <FieldLabel for="domain">
      Domain
    </FieldLabel>
    <Input
      id="domain"
      v-model="domain"
      aria-describedby="domain-description"
    />
    <FieldDescription id="domain-description">
      Without the protocol.
    </FieldDescription>
  </Field>
</template>
