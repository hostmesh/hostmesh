// SPDX-License-Identifier: Apache-2.0
import { readFileSync } from 'node:fs'

export interface Account {
  name: string
  email: string
  password: string
}

export interface Administrator extends Account {
  totp_secret: string
}

/** What `php artisan e2e:seed` prints (control-plane/app/Console/EndToEnd/Fixtures.php). */
export interface Seed {
  branding: { product_name: string, primary_light: string, support_url: string }
  administrators: Record<'login' | 'wrong-code' | 'second-factor' | 'branding' | 'logout' | 'isolation' | 'account', Administrator>
  customers: Record<'login' | 'logout' | 'two-factor', Account>
}

function read(): Seed {
  const file = new URL('../.seed.json', import.meta.url)
  try {
    return JSON.parse(readFileSync(file, 'utf8')) as Seed
  } catch (cause) {
    throw new Error('web/e2e/.seed.json is missing or unreadable. Run: make -C infrastructure/development e2e', { cause })
  }
}

/** The accounts and branding of this run. Made by `make e2e`; never committed. */
export const seed = read()
