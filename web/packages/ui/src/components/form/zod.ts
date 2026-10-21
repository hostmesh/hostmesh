// SPDX-License-Identifier: Apache-2.0
import { z } from 'zod'

// Zod compiles object parsers with `new Function` and, to find out whether
// it may, calls `Function("")` once. The production policy has no
// 'unsafe-eval', so that probe raises a `securitypolicyviolation` event
// although zod catches the error and falls back. `jitless` is
// the switch zod documents for such policies; it skips the probe as well.
// Set here, where the apps get `z` from, before any of them builds a schema.
z.config({ jitless: true })

export { z }
