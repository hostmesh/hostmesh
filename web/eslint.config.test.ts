// SPDX-License-Identifier: Apache-2.0
import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

// The import restrictions of eslint.config.js, applied to code as if it sat
// at the given path. The files need not exist.
const eslint = new ESLint({ cwd: import.meta.dirname })

async function restrictedImports(filePath: string, code: string): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath })
  return (result?.messages ?? [])
    .filter((message) => message.ruleId === 'no-restricted-imports')
    .map((message) => message.message)
}

const vue = (script: string) => `<script setup lang="ts">\n${script}\n</script>\n`

// Issue #223: zod runs with `jitless` only when imported through @hostmesh/ui.
describe('an app', () => {
  it.each([
    ['apps/admin/src/views/ExampleView.vue', vue(`import { z } from 'zod'\nz.string()`)],
    ['apps/portal/src/lib/example.ts', `import * as z from 'zod'\nz.string()\n`],
    ['apps/admin/src/lib/example.ts', `import { z } from 'zod/v4'\nz.string()\n`],
    ['apps/portal/src/lib/example.ts', `import * as z from 'zod/mini'\nz.string()\n`],
  ])('imports zod only through @hostmesh/ui: %s', async (filePath, code) => {
    const messages = await restrictedImports(filePath, code)

    expect(messages).toHaveLength(1)
    expect(messages[0]).toContain('Import z from @hostmesh/ui')
  })

  it('may import z from @hostmesh/ui', async () => {
    await expect(restrictedImports('apps/admin/src/lib/example.ts', `import { z } from '@hostmesh/ui'\nz.string()\n`)).resolves.toEqual([])
  })

  it('still may not import an HTTP library', async () => {
    const messages = await restrictedImports('apps/portal/src/lib/example.ts', `import axios from 'axios'\naxios.get('/')\n`)

    expect(messages).toHaveLength(1)
    expect(messages[0]).toContain('@hostmesh/api-client')
  })
})

describe('the component package', () => {
  it.each([
    'packages/ui/src/components/form/zod.ts',
    'packages/ui/src/components/form/typed-schema.test.ts',
  ])('may import zod: %s', async (filePath) => {
    await expect(restrictedImports(filePath, `import { z } from 'zod'\nz.string()\n`)).resolves.toEqual([])
  })

  it('still may not import an HTTP library', async () => {
    const messages = await restrictedImports('packages/ui/src/lib/example.ts', `import ky from 'ky'\nky.get('/')\n`)

    expect(messages).toHaveLength(1)
    expect(messages[0]).toContain('@hostmesh/api-client')
  })
})
