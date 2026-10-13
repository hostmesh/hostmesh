// SPDX-License-Identifier: Apache-2.0

// Spec section 28: every hostname derives from HOSTMESH_DOMAIN.
const domain = process.env.HOSTMESH_DOMAIN ?? 'hostmesh.test'

export const ADMIN = `https://admin.${domain}`
export const PORTAL = `https://app.${domain}`
export const API = `https://api.${domain}/api/v1`

// control-plane/config/hostmesh.php, `realms`.
export const ADMIN_SESSION_COOKIE = '__Host-hostmesh-admin-session'
export const PORTAL_SESSION_COOKIE = '__Host-hostmesh-portal-session'
