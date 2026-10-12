// SPDX-License-Identifier: Apache-2.0
import { vi } from 'vitest'

/** The API root the tests' page (vite.config.ts) derives. */
export const API = 'https://api.hostmesh.test/api/v1'

type Reply = Response | ((request: Request) => Response | Promise<Response>)

export function json(body: unknown, status = 200): Response {
  return Response.json(body, { status })
}

export function noContent(): Response {
  return new Response(null, { status: 204 })
}

function key(request: Request): string {
  return `${request.method} ${request.url.slice(API.length).split('?')[0] ?? ''}`
}

/**
 * Replaces the global `fetch` with a table of replies, keyed
 * `"<METHOD> <path under the API root>"`. A request without an entry is
 * answered 500 and named in the message. `routes` may be changed while a
 * test runs. Undo with `vi.unstubAllGlobals()`.
 */
export function mockApi(routes: Record<string, Reply>) {
  const requests: Request[] = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (request: Request): Promise<Response> => {
      requests.push(request.clone())
      const reply = routes[key(request)]
      if (reply === undefined) {
        return json({ message: `Unexpected request: ${key(request)}` }, 500)
      }
      return typeof reply === 'function' ? reply(request) : reply.clone()
    }),
  )
  return {
    routes,
    requests,
    /** The requests sent to one route, oldest first. */
    sent: (route: string): Request[] => requests.filter((request) => key(request) === route),
  }
}
