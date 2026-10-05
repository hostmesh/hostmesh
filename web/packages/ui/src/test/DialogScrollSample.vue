<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import {
  Button,
  Dialog,
  DialogDescription,
  DialogHeader,
  DialogScrollContent,
  DialogTitle,
  DialogTrigger,
} from '../index'
</script>

<template>
  <Dialog>
    <DialogTrigger as-child>
      <Button>Show terms</Button>
    </DialogTrigger>
    <DialogScrollContent>
      <DialogHeader>
        <DialogTitle>Terms of service</DialogTitle>
        <DialogDescription>A long text that scrolls with the page.</DialogDescription>
      </DialogHeader>
    </DialogScrollContent>
  </Dialog>
</template>
