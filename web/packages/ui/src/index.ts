// SPDX-License-Identifier: Apache-2.0
//
// The public surface of @hostmesh/ui. Apps import from here and from
// `@hostmesh/ui/styles.css`, never from a path inside the package.
export * from '#ui/components/ui/button'
export * from '#ui/components/ui/card'
export * from '#ui/components/ui/dialog'
export * from '#ui/components/ui/field'
export * from '#ui/components/ui/input'
export * from '#ui/components/ui/label'
export * from '#ui/components/ui/native-select'
export * from '#ui/components/ui/separator'
export * from '#ui/components/ui/sonner'
export * from '#ui/components/ui/table'
export { default as DataTable } from './components/data-table/DataTable.vue'
export { default as FormField } from './components/form/FormField.vue'
export { default as QrCode } from './components/qr-code/QrCode.vue'
export { toTypedSchema } from './components/form/typed-schema'
// Re-exported so that apps write forms without depending on vee-validate and
// Zod, and with the Zod version the adapter is tested against. This `z`
// never evaluates code, which the production policy forbids.
export { useForm } from 'vee-validate'
export { z } from './components/form/zod'
export {
  createDataTableColumnHelper,
  dataTableFeatures,
  type DataTableFeatures,
  type DataTableSorting,
} from './components/data-table/features'
export { cn } from '#ui/lib/utils'
// Re-exported so that apps raise toasts without depending on vue-sonner.
export { toast } from 'vue-sonner'
export {
  COLOR_MODE_STORAGE_KEY,
  useColorMode,
  type ColorMode,
  type ColorModePreference,
  type UseColorMode,
} from './composables/color-mode'
