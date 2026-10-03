// SPDX-License-Identifier: Apache-2.0
import { useColorMode } from '@hostmesh/ui'
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import { createWebHistory } from 'vue-router'

import App from './App.vue'
import { createAppRouter } from './router'
import './style.css'

// Light or dark from the system setting, before the first render.
useColorMode()

createApp(App).use(createPinia()).use(createAppRouter(createWebHistory())).mount('#app')
