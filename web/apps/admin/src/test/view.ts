// SPDX-License-Identifier: Apache-2.0
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { vi } from 'vitest'
import type { Component } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

import { useAuthStore } from '../stores/auth'

/**
 * Mounts one view or layout in the document, with a fresh Pinia and a
 * router that accepts every path and has no guard: a test reads where the
 * component navigated from `router.currentRoute`. Call mockApi() first.
 */
export async function mountView(component: Component, path = '/') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(component, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router, auth: useAuthStore() }
}

/**
 * Waits for a submitted form. vee-validate 4 runs a schema validation, the
 * one of `handleSubmit` included, behind a 5 ms timer, so `flushPromises()`
 * alone returns before the form has validated or sent anything.
 */
export async function submitted(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 10))
  await flushPromises()
}

/** jsdom has no matchMedia: answer as a system set to light or dark would. */
export function stubSystemColorScheme(dark: boolean): void {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: dark && query === '(prefers-color-scheme: dark)',
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }))
}
