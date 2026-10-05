<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, toast, Toaster } from '../index'
</script>

<template>
  <Button @click="toast('Website created')">
    Notify
  </Button>
  <Toaster />
</template>
