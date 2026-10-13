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
