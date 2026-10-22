<!-- SPDX-License-Identifier: Apache-2.0 -->
<script setup lang="ts">
import type { components } from '@hostmesh/api-client'
import {
  Button,
  createDataTableColumnHelper,
  DataTable,
  type DataTableSorting,
  Field,
  FieldLabel,
  Input,
  NativeSelect,
  NativeSelectOption,
} from '@hostmesh/ui'
import { computed, h, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { api } from '../../api'
import AppNav from '../../components/AppNav.vue'
import AppLayout from '../../layouts/AppLayout.vue'
import { fromDateTimeLocal, toDateTimeLocal } from '../../lib/datetime'
import { ACTION_LABELS, ACTOR_TYPE_LABELS, isAction, isActorType, type ActorType } from './labels'
import { auditQuery, withoutEmpty, type AuditQuery } from './query'

type AuditEvent = components['schemas']['AuditEventResource']

// Issue #150, spec section 21: the audit log, filtered, sorted and paged by
// the API (decision 0019). The address holds the view: a reload or the back
// button shows the same page.
const route = useRoute()
const router = useRouter()
const query = computed(() => auditQuery(route.query))

const state = ref<'ready' | 'forbidden' | 'invalid' | 'failed'>('ready')
const loading = ref(true)
const invalid = ref('')
const events = ref<AuditEvent[]>([])
const cursors = ref<{ next: string | null; prev: string | null }>({ next: null, prev: null })

async function load(): Promise<void> {
  loading.value = true
  try {
    const { data, error, response } = await api.GET('/admin/audit-events', { params: { query: query.value } })
    if (data) {
      events.value = data.data
      cursors.value = { next: data.meta.next_cursor, prev: data.meta.prev_cursor }
      state.value = 'ready'
    } else if (response.status === 403) {
      state.value = 'forbidden'
    } else if (response.status === 422) {
      invalid.value = (error as { message?: string } | undefined)?.message ?? 'The filters are not valid.'
      state.value = 'invalid'
    } else {
      state.value = 'failed'
    }
  } catch {
    state.value = 'failed'
  } finally {
    loading.value = false
  }
}

// Every change of the address is a new request: filters, order and page.
watch(() => JSON.stringify(query.value), load, { immediate: true })

function show(next: AuditQuery): void {
  void router.push({ query: withoutEmpty(next) })
}

// A new order or new filters start at the first page: a cursor belongs to
// the order and the filters it was issued for.
const sorting = computed<DataTableSorting>({
  get: () => [{ id: 'created_at', desc: query.value.sort !== 'created_at' }],
  set: (next) => {
    const oldestFirst = next[0]?.desc === false
    show({ ...query.value, cursor: undefined, sort: oldestFirst ? 'created_at' : undefined })
  },
})

const filters = reactive({ action: '', actorType: '', resource: '', from: '', to: '' })

watch(
  query,
  (current) => {
    filters.action = current.action ?? ''
    filters.actorType = current.actor_type ?? ''
    filters.resource = current.resource ?? ''
    filters.from = toDateTimeLocal(current.from)
    filters.to = toDateTimeLocal(current.to)
  },
  { immediate: true },
)

function applyFilters(): void {
  show({
    action: isAction(filters.action) ? filters.action : undefined,
    actor_type: isActorType(filters.actorType) ? filters.actorType : undefined,
    resource: filters.resource.trim() || undefined,
    from: fromDateTimeLocal(filters.from),
    to: fromDateTimeLocal(filters.to),
    sort: query.value.sort,
  })
}

function clearFilters(): void {
  show({ sort: query.value.sort })
}

// Decision 0013: an event holds identifiers; a name is looked up when the
// log is read and is null when the account is gone.
function actorName(actor: AuditEvent['actor']): string {
  if (actor.name !== null) {
    return actor.name
  }
  if (actor.type === 'system') {
    return 'System'
  }
  return actor.id === null ? 'Unknown' : 'Deleted account'
}

function actorTypeLabel(type: string): string {
  return isActorType(type) ? ACTOR_TYPE_LABELS[type] : type
}

const timeFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' })

const columnHelper = createDataTableColumnHelper<AuditEvent>()

const columns = columnHelper.columns([
  columnHelper.accessor('created_at', {
    header: 'Time',
    enableSorting: true,
    cell: (info) => h('time', { datetime: info.getValue() }, timeFormat.format(new Date(info.getValue()))),
  }),
  columnHelper.display({
    id: 'actor',
    header: 'Actor',
    cell: ({ row }) =>
      h('div', [
        h('div', actorName(row.original.actor)),
        h('div', { class: 'text-xs text-muted-foreground' }, actorTypeLabel(row.original.actor.type)),
      ]),
  }),
  columnHelper.accessor('action', {
    header: 'Action',
    cell: (info) => {
      const action = info.getValue()
      return isAction(action) ? ACTION_LABELS[action] : action
    },
  }),
  columnHelper.display({
    id: 'resource',
    header: 'Resource',
    cell: ({ row }) =>
      h('div', [
        h('div', { class: 'text-xs text-muted-foreground' }, row.original.resource.type),
        h('div', row.original.resource.name ?? row.original.resource.id ?? '—'),
      ]),
  }),
  columnHelper.accessor('request_id', {
    header: 'Request ID',
    cell: (info) => h('code', { class: 'text-xs' }, info.getValue() ?? '—'),
  }),
])

const actions = Object.entries(ACTION_LABELS)
const actorTypes = Object.entries(ACTOR_TYPE_LABELS) as [ActorType, string][]
</script>

<template>
  <AppLayout>
    <template #nav>
      <AppNav />
    </template>
    <div class="flex flex-col gap-6">
      <h1
        tabindex="-1"
        class="text-2xl font-semibold outline-none"
      >
        Audit log
      </h1>
      <p
        v-if="state === 'forbidden'"
        role="alert"
        class="text-sm"
      >
        You do not have permission to view the audit log.
      </p>
      <div
        v-else-if="state === 'failed'"
        role="alert"
        class="flex flex-col items-start gap-2 text-sm"
      >
        <p>The audit log could not be loaded.</p>
        <Button
          type="button"
          variant="outline"
          data-test="retry"
          @click="load"
        >
          Try again
        </Button>
      </div>
      <DataTable
        v-else
        v-model:sorting="sorting"
        :columns="columns"
        :data="events"
        :loading="loading"
        :pagination="{ previous: cursors.prev !== null, next: cursors.next !== null }"
        @previous="show({ ...query, cursor: cursors.prev ?? undefined })"
        @next="show({ ...query, cursor: cursors.next ?? undefined })"
      >
        <template #toolbar>
          <form
            role="search"
            aria-label="Filter the audit log"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end"
            @submit.prevent="applyFilters"
          >
            <Field class="lg:col-span-2">
              <FieldLabel for="audit-action">
                Action
              </FieldLabel>
              <NativeSelect
                id="audit-action"
                v-model="filters.action"
                class="w-full"
              >
                <NativeSelectOption value="">
                  Any action
                </NativeSelectOption>
                <NativeSelectOption
                  v-for="[value, label] in actions"
                  :key="value"
                  :value="value"
                >
                  {{ label }}
                </NativeSelectOption>
              </NativeSelect>
            </Field>
            <Field>
              <FieldLabel for="audit-actor-type">
                Actor type
              </FieldLabel>
              <NativeSelect
                id="audit-actor-type"
                v-model="filters.actorType"
                class="w-full"
              >
                <NativeSelectOption value="">
                  Any actor
                </NativeSelectOption>
                <NativeSelectOption
                  v-for="[value, label] in actorTypes"
                  :key="value"
                  :value="value"
                >
                  {{ label }}
                </NativeSelectOption>
              </NativeSelect>
            </Field>
            <Field>
              <FieldLabel for="audit-resource">
                Resource type
              </FieldLabel>
              <Input
                id="audit-resource"
                v-model="filters.resource"
                maxlength="64"
                placeholder="branding"
              />
            </Field>
            <Field>
              <FieldLabel for="audit-from">
                From
              </FieldLabel>
              <Input
                id="audit-from"
                v-model="filters.from"
                type="datetime-local"
              />
            </Field>
            <Field>
              <FieldLabel for="audit-to">
                To
              </FieldLabel>
              <Input
                id="audit-to"
                v-model="filters.to"
                type="datetime-local"
              />
            </Field>
            <div class="flex gap-2 lg:col-span-6">
              <Button type="submit">
                Apply filters
              </Button>
              <Button
                type="button"
                variant="outline"
                @click="clearFilters"
              >
                Clear
              </Button>
            </div>
            <p
              v-if="state === 'invalid'"
              role="alert"
              class="text-sm text-destructive lg:col-span-6"
            >
              {{ invalid }}
            </p>
          </form>
        </template>
        <template #empty>
          No audit events match.
        </template>
      </DataTable>
    </div>
  </AppLayout>
</template>
