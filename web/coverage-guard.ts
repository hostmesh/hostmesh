// SPDX-License-Identifier: Apache-2.0
import { existsSync, readdirSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

// Issue #145: on a report that covers no file Vitest computes no percentage
// ("Unknown"), and no threshold fails. That happens with `--project <name>`
// and with a coverage `include` that matches nothing. This reporter fails
// the run instead, and also when one package of the workspace has no file in
// the report. It acts only on a run with coverage: `onCoverage` is not called
// otherwise.

const GROUPS = ['apps', 'packages'] as const

/** The packages of the workspace whose sources coverage measures: apps/<name> and packages/<name> with a src directory. */
export function sourcePackages(root: string): string[] {
  return GROUPS.flatMap((group) => {
    const directory = join(root, group)
    if (!existsSync(directory)) {
      return []
    }
    return readdirSync(directory, { withFileTypes: true })
      .filter((entry) => entry.isDirectory() && existsSync(join(directory, entry.name, 'src')))
      .map((entry) => `${group}/${entry.name}`)
      .sort()
  })
}

/** What is wrong with a coverage report of `files` (absolute paths), or nothing. */
export function coverageProblems(root: string, files: readonly string[], packages: readonly string[]): string[] {
  if (files.length === 0) {
    return [
      'The coverage report covers no file. Run coverage for the whole workspace, never with --project, and check coverage.include in vitest.config.ts.',
    ]
  }
  const covered = new Set(files.map((file) => relative(root, file).split(sep).slice(0, 2).join('/')))
  return packages
    .filter((name) => !covered.has(name))
    .map((name) => `The coverage report has no file of ${name}. Check coverage.include in vitest.config.ts.`)
}

/** Istanbul's coverage map, as far as this reporter reads it. */
function filesOf(coverage: unknown): string[] {
  if (typeof coverage === 'object' && coverage !== null && 'files' in coverage && typeof coverage.files === 'function') {
    const files: unknown = coverage.files()
    return Array.isArray(files) ? files.filter((file): file is string => typeof file === 'string') : []
  }
  return []
}

interface Context {
  config: { root: string }
  logger: { error: (message: string) => void }
}

export interface CoverageGuardOptions {
  /** Lists the packages a report must have files of. Tests replace it. */
  sourcePackages?: (root: string) => string[]
}

export default class CoverageGuard {
  private context: Context | undefined
  private readonly listPackages: (root: string) => string[]

  constructor(options: CoverageGuardOptions = {}) {
    this.listPackages = options.sourcePackages ?? sourcePackages
  }

  onInit(context: Context): void {
    this.context = context
  }

  onCoverage(coverage: unknown): void {
    const root = this.context?.config.root ?? process.cwd()
    const problems = coverageProblems(root, filesOf(coverage), this.listPackages(root))
    for (const problem of problems) {
      this.context?.logger.error(`ERROR: ${problem}`)
    }
    if (problems.length > 0) {
      process.exitCode = 1
    }
  }
}
