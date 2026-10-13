<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { encode } from 'uqr'
import { computed } from 'vue'

// A QR code as an inline SVG: one square per dark module. No inline style
// (the Content Security Policy forbids it) and no color literal: the
// modules take `currentColor`, the background a token. The scannable pair
// is dark on light in both color modes: authenticator apps do not read a
// code that is light on dark (issue #124).
const props = defineProps<{
  value: string
  /** The text alternative of the image. */
  label: string
}>()

// A quiet zone of four modules, as the QR specification asks.
const code = computed(() => encode(props.value, { border: 4 }))

const path = computed(() => {
  let d = ''
  code.value.data.forEach((row, y) => {
    row.forEach((dark, x) => {
      if (dark) {
        d += `M${x} ${y}h1v1h-1z`
      }
    })
  })
  return d
})
</script>

<template>
  <svg
    role="img"
    :aria-label="props.label"
    :viewBox="`0 0 ${code.size} ${code.size}`"
    shape-rendering="crispEdges"
    class="size-48 bg-scannable text-scannable-foreground"
  >
    <path
      :d="path"
      fill="currentColor"
    />
  </svg>
</template>
