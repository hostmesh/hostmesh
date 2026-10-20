// SPDX-License-Identifier: Apache-2.0
import { Secret, TOTP } from 'otpauth'

/**
 * The code an authenticator app shows now (decision 0011: SHA-1, 6 digits,
 * 30 seconds). The API accepts the step before and after as well, so a
 * code made at the end of a step is still good when it arrives.
 */
export function totp(secret: string): string {
  return new TOTP({ secret: Secret.fromBase32(secret), algorithm: 'SHA1', digits: 6, period: 30 }).generate()
}

const PERIOD = 30

function stepAt(milliseconds: number): number {
  return Math.floor(milliseconds / 1000 / PERIOD)
}

/**
 * A code for a time step after `used`, the step of the last code the
 * account accepted: the API refuses a step it has seen (decision 0011).
 * It may be the step after the current one, which the API accepts as well;
 * when even that is used, this waits for the clock. Returns the code and
 * its step, to pass as `used` the next time.
 */
export async function codeAfter(secret: string, used: number): Promise<{ code: string, step: number }> {
  for (;;) {
    const now = Date.now()
    const step = Math.max(stepAt(now), used + 1)
    if (step <= stepAt(now) + 1) {
      const code = new TOTP({ secret: Secret.fromBase32(secret), algorithm: 'SHA1', digits: 6, period: PERIOD })
        .generate({ timestamp: step * PERIOD * 1000 })
      return { code, step }
    }
    await new Promise((resolve) => setTimeout(resolve, (stepAt(now) + 1) * PERIOD * 1000 - now + 100))
  }
}
