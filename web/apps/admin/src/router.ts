// SPDX-License-Identifier: Apache-2.0
import { createRouter, type Router, type RouterHistory } from 'vue-router'
import HomeView from './HomeView.vue'

export function createAppRouter(history: RouterHistory): Router {
  return createRouter({
    history,
    routes: [{ path: '/', name: 'home', component: HomeView }],
  })
}
