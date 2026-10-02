// SPDX-License-Identifier: Apache-2.0
import eslint from '@eslint/js'
import { defineConfig, globalIgnores } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'

const apiClientOnly =
  'Call the API through @hostmesh/api-client (spec section 29: no privileged path).'

export default defineConfig(
  globalIgnores(['**/dist/', '**/coverage/', '**/generated/']),
  {
    files: ['**/*.{js,ts,vue}'],
    extends: [
      eslint.configs.recommended,
      tseslint.configs.strict,
      pluginVue.configs['flat/recommended'],
    ],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.browser,
      parserOptions: {
        parser: tseslint.parser,
      },
    },
  },
  {
    // The apps and the component package never talk to the network themselves.
    files: ['apps/**/*.{ts,vue}', 'packages/ui/**/*.{ts,vue}'],
    rules: {
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: apiClientOnly },
        { name: 'XMLHttpRequest', message: apiClientOnly },
      ],
      'no-restricted-imports': [
        'error',
        { paths: [{ name: 'openapi-fetch', message: apiClientOnly }] },
      ],
    },
  },
)
