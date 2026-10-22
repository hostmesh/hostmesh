<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { ColorModeSetting, useColorMode } from '../index'

// Another caller, as the app's logo is.
const { mode } = useColorMode()
</script>

<template>
  <ColorModeSetting />
  <output data-testid="mode">{{ mode }}</output>
</template>
