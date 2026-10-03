<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { Button, Card, CardContent, Input } from '../index'
</script>

<template>
  <div>
    <Button>Save</Button>
    <Button variant="secondary">
      Cancel
    </Button>
    <Button variant="destructive">
      Delete
    </Button>
    <Input aria-label="Domain" />
    <Card data-testid="card">
      <CardContent>Card</CardContent>
    </Card>
    <p
      class="text-muted-foreground"
      data-testid="muted"
    >
      Muted
    </p>
    <div
      class="border"
      data-testid="bordered"
    >
      Bordered
    </div>
    <div
      class="bg-accent text-accent-foreground"
      data-testid="accent"
    >
      Accent
    </div>
    <code
      class="font-mono"
      data-testid="mono"
    >mono</code>
  </div>
</template>
