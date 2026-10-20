<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue'

import type { Result } from '../../lib/result'
import { useAuthStore, type PasswordAndCode } from '../../stores/auth'
import PasswordAndCodeForm from './PasswordAndCodeForm.vue'
import RecoveryCodes from './RecoveryCodes.vue'
import TwoFactorSetup from './TwoFactorSetup.vue'

// Two-factor is optional for customers (spec section 28). Whether it is on
// is the API's answer (`two_factor_enabled`), reloaded after every change.
// The section, not the form that asked, acts on a success: the form may be
// gone by then, because the reloaded user shows another state.
const auth = useAuthStore()
const heading = useTemplateRef<HTMLHeadingElement>('heading')

/** Recovery codes the API has just returned. Shown once, until the customer says they are saved. */
const recoveryCodes = ref<string[] | null>(null)

async function focusHeading(): Promise<void> {
  await nextTick()
  heading.value?.focus()
}

async function confirm(code: string): Promise<Result<string[]>> {
  const result = await auth.confirmEnrollment(code)
  if (result.ok) {
    recoveryCodes.value = result.value
  }
  return result
}

async function regenerate(input: PasswordAndCode): Promise<Result<string[]>> {
  const result = await auth.regenerateRecoveryCodes(input)
  if (result.ok) {
    recoveryCodes.value = result.value
  }
  return result
}

async function disable(input: PasswordAndCode): Promise<Result> {
  const result = await auth.disableTwoFactor(input)
  if (result.ok) {
    await focusHeading()
  }
  return result
}

async function saved(): Promise<void> {
  recoveryCodes.value = null
  await focusHeading()
}
</script>

<template>
  <section
    aria-labelledby="two-factor-heading"
    data-test="two-factor"
    class="flex flex-col gap-4"
  >
    <h2
      id="two-factor-heading"
      ref="heading"
      tabindex="-1"
      class="text-lg font-semibold outline-none"
    >
      Two-factor authentication
    </h2>
    <RecoveryCodes
      v-if="recoveryCodes"
      :codes="recoveryCodes"
      @saved="saved"
    />
    <template v-else-if="auth.user?.two_factor_enabled">
      <p class="text-sm">
        Two-factor authentication is on. Logging in needs your password and a code from your authenticator app.
      </p>
      <PasswordAndCodeForm
        title="Recovery codes"
        description="Get new codes if you have lost yours or used most of them. The old codes stop working."
        submit-label="Get new recovery codes"
        :action="regenerate"
      />
      <PasswordAndCodeForm
        title="Turn off two-factor authentication"
        description="Logging in will need only your password."
        submit-label="Turn off two-factor authentication"
        variant="destructive"
        :action="disable"
      />
    </template>
    <TwoFactorSetup
      v-else
      :confirm="confirm"
    />
  </section>
</template>
