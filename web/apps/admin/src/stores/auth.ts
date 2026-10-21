// SPDX-License-Identifier: Apache-2.0
import { pendingSecondFactor, secondFactorStep, type components } from '@hostmesh/api-client'
import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '../api'
import { failure, type Result } from '../lib/result'

/** The logged-in account, with the abilities the API reports for it. */
export type Account = components['schemas']['AdministratorResource']

/** Where a session stands. The router has one entry route for each. */
export type SessionState = 'guest' | 'challenge' | 'enrollment' | 'authenticated'
export type AuthStatus = 'unknown' | SessionState

export interface Enrollment {
  secret: string
  provisioning_uri: string
}

export type ChallengeInput = { code: string } | { recovery_code: string }

/** What turning two-factor off and replacing the recovery codes ask for. */
export interface PasswordAndCode {
  password: string
  code: string
}

const done: Result = { ok: true, value: undefined }

// A call that got no response throws; every action reports it the same way.
async function attempt<T>(run: () => Promise<Result<T>>): Promise<Result<T>> {
  try {
    return await run()
  } catch {
    return failure(undefined, undefined)
  }
}

export const useAuthStore = defineStore('auth', () => {
  const status = ref<AuthStatus>('unknown')
  const user = ref<Account | null>(null)

  /** The session is gone: a logout, or a 401 on any call. */
  function expire(): void {
    user.value = null
    status.value = 'guest'
  }

  /** Asks the API where the session stands. Called once when the app starts. */
  async function restore(): Promise<void> {
    try {
      const { data, error, response } = await api.GET('/admin/auth/user')
      if (data) {
        user.value = data.data
        status.value = 'authenticated'
        return
      }
      user.value = null
      status.value = (response.status === 403 ? pendingSecondFactor(error) : undefined) ?? 'guest'
    } catch {
      expire()
    }
  }

  function login(credentials: { email: string, password: string }): Promise<Result> {
    return attempt(async () => {
      await api.GET('/admin/auth/csrf-cookie')
      const { data, error, response } = await api.POST('/admin/auth/login', { body: credentials })
      if (!data) {
        return failure(response, error)
      }
      const step = secondFactorStep(data.meta.second_factor)
      user.value = step === 'none' ? data.data : null
      status.value = step === 'none' ? 'authenticated' : step
      return done
    })
  }

  function completeChallenge(input: ChallengeInput): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/admin/auth/two-factor/challenge', { body: input })
      if (!response.ok) {
        return failure(response, error)
      }
      await restore()
      return done
    })
  }

  function startEnrollment(): Promise<Result<Enrollment>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/admin/auth/two-factor')
      return data ? { ok: true, value: data.data } : failure(response, error)
    })
  }

  /**
   * Turns two-factor on. The recovery codes are shown once, so the status
   * stays `enrollment` until the view calls finishEnrollment().
   */
  function confirmEnrollment(code: string): Promise<Result<string[]>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/admin/auth/two-factor/confirm', { body: { code } })
      return data ? { ok: true, value: data.data.recovery_codes } : failure(response, error)
    })
  }

  function finishEnrollment(): Promise<void> {
    return restore()
  }

  /** Every earlier recovery code stops working. The new ones are shown only once. */
  function regenerateRecoveryCodes(input: PasswordAndCode): Promise<Result<string[]>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/admin/auth/two-factor/recovery-codes', { body: input })
      return data ? { ok: true, value: data.data.recovery_codes } : failure(response, error)
    })
  }

  /**
   * Two-factor is required in admin (spec section 28): afterwards the
   * session owes enrollment, and the status says so.
   */
  function disableTwoFactor(input: PasswordAndCode): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/admin/auth/two-factor/disable', { body: input })
      if (!response.ok) {
        return failure(response, error)
      }
      await restore()
      return done
    })
  }

  function logout(): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/admin/auth/logout')
      // 401: the session had already ended, which is what was asked for.
      if (!response.ok && response.status !== 401) {
        return failure(response, error)
      }
      expire()
      return done
    })
  }

  return {
    status,
    user,
    restore,
    login,
    completeChallenge,
    startEnrollment,
    confirmEnrollment,
    finishEnrollment,
    regenerateRecoveryCodes,
    disableTwoFactor,
    logout,
    expire,
  }
})
