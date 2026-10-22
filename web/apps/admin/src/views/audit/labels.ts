// SPDX-License-Identifier: Apache-2.0
import type { components } from '@hostmesh/api-client'

export type AuditAction = components['schemas']['AuditAction']
export type ActorType = components['schemas']['ActorType']

// Typed by the generated schema: an action or actor type the API adds fails
// the typecheck here until it has a label.
export const ACTION_LABELS: Record<AuditAction, string> = {
  'auth.login.succeeded': 'Login succeeded',
  'auth.login.failed': 'Login failed',
  'auth.logout': 'Logged out',
  'auth.two_factor.enrollment_started': 'Two-factor setup started',
  'auth.two_factor.confirmed': 'Two-factor turned on',
  'auth.two_factor.disabled': 'Two-factor turned off',
  'auth.two_factor.challenge_passed': 'Two-factor code accepted',
  'auth.two_factor.challenge_failed': 'Two-factor code refused',
  'auth.two_factor.recovery_code_used': 'Recovery code used',
  'auth.two_factor.recovery_codes_regenerated': 'Recovery codes replaced',
  'administrator.created': 'Administrator created',
  'administrator.role_assigned': 'Role assigned',
  'administrator.role_revoked': 'Role revoked',
  'administrator.two_factor_reset': 'Two-factor reset',
  'branding.updated': 'Branding updated',
  'branding.image_replaced': 'Branding image replaced',
  'branding.image_removed': 'Branding image removed',
  'audit_log.events_deleted': 'Audit events deleted',
  'audit_log.addresses_cleared': 'Audit addresses cleared',
}

export const ACTOR_TYPE_LABELS: Record<ActorType, string> = {
  administrator: 'Administrator',
  user: 'Customer',
  node: 'Node',
  system: 'System',
}

export function isAction(value: string): value is AuditAction {
  return Object.hasOwn(ACTION_LABELS, value)
}

export function isActorType(value: string): value is ActorType {
  return Object.hasOwn(ACTOR_TYPE_LABELS, value)
}
