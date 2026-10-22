// SPDX-License-Identifier: Apache-2.0
import { pendingSecondFactor, secondFactorStep, type components } from '@hostmesh/api-client'
import { defineStore } from 'pinia'
import { ref } from 'vue'

import { api } from '../api'
import { failure, type Result } from '../lib/result'

/** The logged-in account, with the abilities the API reports for it. */
export type Account = components['schemas']['UserResource']

/**
 * Where a session stands. Two-factor is optional for customers (spec
 * section 28), so there is no `enrollment` state here: a customer who has
 * it on owes the challenge, everyone else is complete after the password.
 */
export type SessionState = 'guest' | 'challenge' | 'authenticated'
export type AuthStatus = 'unknown' | SessionState

export type ChallengeInput = { code: string } | { recovery_code: string }

export interface Enrollment {
  secret: string
  provisioning_uri: string
}

/** What turning two-factor off and replacing the recovery codes ask for. */
export interface PasswordAndCode {
  password: string
  code: string
}

/** What changing the password asks for (decision 0023). `code` where two-factor is on. */
export type PasswordChange = components['schemas']['ChangePasswordRequest']

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
      const { data, error, response } = await api.GET('/customer/auth/user')
      if (data) {
        user.value = data.data
        status.value = 'authenticated'
        return
      }
      user.value = null
      status.value = response.status === 403 && pendingSecondFactor(error) === 'challenge' ? 'challenge' : 'guest'
    } catch {
      expire()
    }
  }

  function login(credentials: { email: string, password: string }): Promise<Result> {
    return attempt(async () => {
      await api.GET('/customer/auth/csrf-cookie')
      const { data, error, response } = await api.POST('/customer/auth/login', { body: credentials })
      if (!data) {
        return failure(response, error)
      }
      const owesChallenge = secondFactorStep(data.meta.second_factor) === 'challenge'
      user.value = owesChallenge ? null : data.data
      status.value = owesChallenge ? 'challenge' : 'authenticated'
      return done
    })
  }

  function completeChallenge(input: ChallengeInput): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/customer/auth/two-factor/challenge', { body: input })
      if (!response.ok) {
        return failure(response, error)
      }
      await restore()
      return done
    })
  }

  function startEnrollment(): Promise<Result<Enrollment>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/customer/auth/two-factor')
      return data ? { ok: true, value: data.data } : failure(response, error)
    })
  }

  /** Turns two-factor on and returns the recovery codes, which the API shows only once. */
  function confirmEnrollment(code: string): Promise<Result<string[]>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/customer/auth/two-factor/confirm', { body: { code } })
      if (!data) {
        return failure(response, error)
      }
      await restore()
      return { ok: true, value: data.data.recovery_codes }
    })
  }

  /** Every earlier recovery code stops working. The new ones are shown only once. */
  function regenerateRecoveryCodes(input: PasswordAndCode): Promise<Result<string[]>> {
    return attempt(async () => {
      const { data, error, response } = await api.POST('/customer/auth/two-factor/recovery-codes', { body: input })
      return data ? { ok: true, value: data.data.recovery_codes } : failure(response, error)
    })
  }

  function disableTwoFactor(input: PasswordAndCode): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/customer/auth/two-factor/disable', { body: input })
      if (!response.ok) {
        return failure(response, error)
      }
      await restore()
      return done
    })
  }

  /**
   * The session goes on; the account's other sessions end on the server.
   * Nothing to fetch again: the account looks the same.
   */
  function changePassword(input: PasswordChange): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/customer/auth/password', { body: input })
      return response.ok ? done : failure(response, error)
    })
  }

  function logout(): Promise<Result> {
    return attempt(async () => {
      const { error, response } = await api.POST('/customer/auth/logout')
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
    regenerateRecoveryCodes,
    disableTwoFactor,
    changePassword,
    logout,
    expire,
  }
})
