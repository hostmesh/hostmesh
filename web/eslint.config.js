// SPDX-License-Identifier: Apache-2.0
import eslint from '@eslint/js'
import { defineConfig, globalIgnores } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'

const apiClientOnly =
  'Call the API through @hostmesh/api-client (spec section 29: no privileged path).'

// Every way to name the global object, crossed with the request APIs.
const networkProperties = ['window', 'globalThis', 'self'].flatMap((object) =>
  ['fetch', 'XMLHttpRequest'].map((property) => ({ object, property, message: apiClientOnly })),
)

// HTTP clients that would bypass the generated one.
const httpLibraries = ['openapi-fetch', 'axios', 'ky', 'ofetch', 'superagent', 'wretch']

// The files the shadcn-vue CLI writes stay as upstream formats them, so that
// re-running the CLI gives a clean diff (decision 0009). For them, the Vue
// rules beyond `flat/essential` (formatting and conventions) are off. The
// essential rules, typescript-eslint and the network rules below stay on.
const essentialVueRules = new Set(
  pluginVue.configs['flat/essential'].flatMap((config) => Object.keys(config.rules ?? {})),
)
const vueStyleRulesOff = Object.fromEntries(
  pluginVue.configs['flat/recommended']
    .flatMap((config) => Object.keys(config.rules ?? {}))
    .filter((rule) => !essentialVueRules.has(rule))
    .map((rule) => [rule, 'off']),
)

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
    // The apps and the component package never talk to the network
    // themselves. This catches the direct ways to do it; it cannot catch a
    // computed property name or an alias, which are left to review.
    // WebSocket and EventSource are not listed: spec section 28 allows
    // server-sent events for live status, decided when that is built.
    files: ['apps/**/*.{ts,vue}', 'packages/ui/**/*.{ts,vue}'],
    rules: {
      'no-restricted-globals': [
        'error',
        { name: 'fetch', message: apiClientOnly },
        { name: 'XMLHttpRequest', message: apiClientOnly },
      ],
      'no-restricted-properties': [
        'error',
        ...networkProperties,
        { object: 'navigator', property: 'sendBeacon', message: apiClientOnly },
      ],
      'no-restricted-imports': [
        'error',
        { paths: httpLibraries.map((name) => ({ name, message: apiClientOnly })) },
      ],
    },
  },
  {
    files: ['packages/ui/src/components/ui/**/*.{ts,vue}'],
    rules: {
      ...vueStyleRulesOff,
      // Upstream names components Button.vue, Input.vue and Card.vue.
      'vue/multi-word-component-names': 'off',
    },
  },
)
