// SPDX-License-Identifier: Apache-2.0
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'

import App from '../App.vue'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'

/** Mounts the whole app, with its real router and guard, and opens `path`. Call mockApi() first. */
export async function mountApp(path: string) {
  const pinia = createPinia()
  // The guard runs before the app is mounted.
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  await router.isReady()
  const wrapper = mount(App, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router, auth: useAuthStore() }
}
