// SPDX-License-Identifier: Apache-2.0
import type { Plugin } from 'vite'

import { COLOR_MODE_STORAGE_KEY } from '#ui/composables/color-mode-storage-key'

/**
 * The early color-mode script (issue #142). The app's bundle is a module
 * script, which runs after the page is parsed: by then a dark system has
 * seen the light page. This classic script in <head> blocks the first
 * paint and applies the same mode useColorMode() will (VueUse 14): the
 * stored choice, else the system's. Served from 'self', so the Content
 * Security Policy has no `unsafe-inline` and no hash to keep in step.
 */
export function colorModeScript(): string {
  const key = JSON.stringify(COLOR_MODE_STORAGE_KEY)

  return `(function () {
  var stored = null;
  try {
    stored = window.localStorage.getItem(${key});
  } catch (e) {}
  var mode = stored === 'light' || stored === 'dark'
    ? stored
    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.classList.add(mode);
})();
`
}

/** FNV-1a, 32 bits: a name that changes with the content, not a security measure. */
function fingerprint(text: string): string {
  let hash = 0x811c9dc5
  for (let index = 0; index < text.length; index++) {
    hash ^= text.charCodeAt(index)
    hash = Math.imul(hash, 0x01000193) >>> 0
  }
  return hash.toString(16).padStart(8, '0')
}

/**
 * Where the script is served. Named after its content, like the bundle's
 * own files, so a cached copy is never older than the page that asks for it.
 */
export function colorModeScriptPath(): string {
  return `/assets/color-mode-${fingerprint(colorModeScript())}.js`
}

/** Adds the early script to the app's index.html, in development and in the build. */
export function colorModeEarly(): Plugin {
  const source = colorModeScript()
  const path = colorModeScriptPath()

  return {
    name: 'hostmesh:color-mode-early',
    transformIndexHtml: () => [{ tag: 'script', attrs: { src: path }, injectTo: 'head' }],
    configureServer(server) {
      server.middlewares.use(path, (_request, response) => {
        response.setHeader('Content-Type', 'text/javascript; charset=utf-8')
        response.end(source)
      })
    },
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: path.slice(1), source })
    },
  }
}
