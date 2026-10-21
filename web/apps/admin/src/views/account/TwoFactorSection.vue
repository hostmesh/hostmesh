<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import type { Result } from '../../lib/result'
import { useAuthStore, type PasswordAndCode } from '../../stores/auth'
import PasswordAndCodeForm from './PasswordAndCodeForm.vue'
import RecoveryCodes from './RecoveryCodes.vue'

// Two-factor is required for administrators (spec section 28), so a
// signed-in administrator always has it on. Turning it off leads straight
// into setting it up again: the guard sends the session, which now owes
// enrollment, to the setup screen and back here afterwards.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const heading = useTemplateRef<HTMLHeadingElement>('heading')

/** Recovery codes the API has just returned. Shown once, until the administrator says they are saved. */
const recoveryCodes = ref<string[] | null>(null)

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
    // The same location again, so that the guard runs. Forced: the router
    // drops a navigation to where it already is before the guard runs.
    const { path, query, hash } = route
    await router.replace({ path, query, hash, force: true })
  }
  return result
}

async function saved(): Promise<void> {
  recoveryCodes.value = null
  await nextTick()
  heading.value?.focus()
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
    <template v-else>
      <p class="text-sm">
        Two-factor authentication is on. It is required for administrators: logging in needs your password and a code from your authenticator app.
      </p>
      <PasswordAndCodeForm
        title="Recovery codes"
        description="Get new codes if you have lost yours or used most of them. The old codes stop working."
        submit-label="Get new recovery codes"
        :action="regenerate"
      />
      <PasswordAndCodeForm
        title="Turn off two-factor authentication"
        description="For example to move to a new authenticator app. Because it is required, you set it up again straight away, with a new QR code and new recovery codes."
        submit-label="Turn off two-factor authentication"
        variant="destructive"
        :action="disable"
      />
    </template>
  </section>
</template>
