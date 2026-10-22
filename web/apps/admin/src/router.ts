// SPDX-License-Identifier: Apache-2.0
import { nextTick } from 'vue'
import { createRouter, type Router, type RouterHistory } from 'vue-router'

import { onUnauthenticated } from './api'
import { safeRedirect } from './lib/redirect'
import { useAuthStore, type SessionState } from './stores/auth'
import HomeView from './views/HomeView.vue'
import LoginView from './views/LoginView.vue'
import TwoFactorChallengeView from './views/TwoFactorChallengeView.vue'
import TwoFactorEnrollmentView from './views/TwoFactorEnrollmentView.vue'
import AccountView from './views/account/AccountView.vue'
import AuditView from './views/audit/AuditView.vue'
import BrandingView from './views/branding/BrandingView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** The session state a route is for. Missing means `authenticated`. */
    requires?: SessionState
  }
}

// Where a session in each state belongs. The API decides the state
// (spec section 28); the guard only follows it.
const ENTRY: Record<Exclude<SessionState, 'authenticated'>, string> = {
  guest: 'login',
  challenge: 'two-factor-challenge',
  enrollment: 'two-factor-setup',
}

export function createAppRouter(history: RouterHistory): Router {
  const router = createRouter({
    history,
    routes: [
      { path: '/', name: 'home', component: HomeView },
      { path: '/branding', name: 'branding', component: BrandingView },
      { path: '/audit', name: 'audit', component: AuditView },
      { path: '/account', name: 'account', component: AccountView },
      { path: '/login', name: 'login', component: LoginView, meta: { requires: 'guest' } },
      {
        path: '/two-factor',
        name: 'two-factor-challenge',
        component: TwoFactorChallengeView,
        meta: { requires: 'challenge' },
      },
      {
        path: '/two-factor/setup',
        name: 'two-factor-setup',
        component: TwoFactorEnrollmentView,
        meta: { requires: 'enrollment' },
      },
      { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
  })

  router.beforeEach(async (to) => {
    const auth = useAuthStore()
    if (auth.status === 'unknown') {
      await auth.restore()
    }
    const state: SessionState = auth.status === 'unknown' ? 'guest' : auth.status
    const requires = to.meta.requires ?? 'authenticated'
    if (requires === state) {
      return true
    }
    if (state === 'authenticated') {
      return { path: safeRedirect(to.query.redirect), replace: true }
    }
    // On the way to a page of the app: remember it. Between the login
    // screens: carry what was remembered.
    const redirect = requires === 'authenticated' ? to.fullPath : to.query.redirect
    return {
      name: ENTRY[state],
      query: typeof redirect === 'string' && redirect !== '/' ? { redirect } : {},
      replace: true,
    }
  })

  // A new page starts at its heading, for keyboard and screen reader users.
  // Not on the first load: the browser starts at the top by itself.
  router.afterEach(async (_to, from, failure) => {
    if (failure || from.matched.length === 0) {
      return
    }
    await nextTick()
    document.querySelector<HTMLElement>('h1')?.focus()
  })

  // Any call that answers 401: the session ended on the server (in admin
  // after 30 idle minutes).
  onUnauthenticated(() => {
    const auth = useAuthStore()
    const hadSession = auth.status !== 'unknown' && auth.status !== 'guest'
    auth.expire()
    if (hadSession) {
      // The same location again, now as a guest. Forced: the router drops a
      // navigation to where it already is before the guard runs.
      const { path, query, hash } = router.currentRoute.value
      void router.replace({ path, query, hash, force: true })
    }
  })

  return router
}
