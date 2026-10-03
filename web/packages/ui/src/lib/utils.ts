// SPDX-License-Identifier: MIT
// Copyright (c) 2023 unovue
// From shadcn-vue 2.8.2. License: ../components/ui/LICENSE.
import type { ClassValue } from 'clsx'
import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
