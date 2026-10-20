// SPDX-License-Identifier: Apache-2.0
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import CoverageGuard, { coverageProblems, sourcePackages } from './coverage-guard'

// Issue #145: with `--project <name>`, or an `include` that matches nothing,
// Vitest reports 0 of 0 and the thresholds pass on the empty report.

const root = '/workspace/web'
const packages = ['apps/admin', 'apps/portal', 'packages/api-client', 'packages/ui']
const fullReport = [
  `${root}/apps/admin/src/main.ts`,
  `${root}/apps/portal/src/main.ts`,
  `${root}/packages/api-client/src/client.ts`,
  `${root}/packages/ui/src/index.ts`,
]

describe('coverageProblems', () => {
  it('accepts a report with files of every package', () => {
    expect(coverageProblems(root, fullReport, packages)).toEqual([])
  })

  it('refuses a report that covers no file', () => {
    expect(coverageProblems(root, [], packages)).toEqual([
      'The coverage report covers no file. Run coverage for the whole workspace, never with --project, and check coverage.include in vitest.config.ts.',
    ])
  })

  it('names every package the report has no file of', () => {
    const report = fullReport.filter((file) => !file.includes('/packages/'))

    expect(coverageProblems(root, report, packages)).toEqual([
      'The coverage report has no file of packages/api-client. Check coverage.include in vitest.config.ts.',
      'The coverage report has no file of packages/ui. Check coverage.include in vitest.config.ts.',
    ])
  })

  it('does not count a file outside the packages for one of them', () => {
    const report = [...fullReport.slice(1), `${root}/apps/admin-old/src/main.ts`]

    expect(coverageProblems(root, report, packages)).toEqual([
      'The coverage report has no file of apps/admin. Check coverage.include in vitest.config.ts.',
    ])
  })
})

describe('sourcePackages', () => {
  let workspace: string

  beforeEach(() => {
    workspace = mkdtempSync(join(tmpdir(), 'coverage-guard-'))
  })

  afterEach(() => {
    rmSync(workspace, { recursive: true, force: true })
  })

  it('lists the apps and packages that have a src directory, in order', () => {
    for (const dir of ['apps/portal/src', 'apps/admin/src', 'packages/ui/src', 'packages/empty']) {
      mkdirSync(join(workspace, dir), { recursive: true })
    }
    writeFileSync(join(workspace, 'apps', 'README.md'), '')

    expect(sourcePackages(workspace)).toEqual(['apps/admin', 'apps/portal', 'packages/ui'])
  })

  it('finds none in a directory without apps or packages', () => {
    expect(sourcePackages(workspace)).toEqual([])
  })
})

describe('the reporter', () => {
  let exitCode: typeof process.exitCode

  beforeEach(() => {
    exitCode = process.exitCode
    process.exitCode = undefined
  })

  afterEach(() => {
    process.exitCode = exitCode
  })

  function guard(packagesOnDisk: string[]): { reporter: CoverageGuard, errors: string[] } {
    const errors: string[] = []
    const reporter = new CoverageGuard({ sourcePackages: () => packagesOnDisk })
    reporter.onInit({ config: { root }, logger: { error: (message: string) => errors.push(message) } })
    return { reporter, errors }
  }

  it('fails the run on an empty report', () => {
    const { reporter, errors } = guard(packages)

    reporter.onCoverage({ files: () => [] })

    expect(process.exitCode).toBe(1)
    expect(errors).toEqual([expect.stringContaining('ERROR: The coverage report covers no file.')])
  })

  it('leaves a complete report alone', () => {
    const { reporter, errors } = guard(packages)

    reporter.onCoverage({ files: () => fullReport })

    expect(process.exitCode).toBeUndefined()
    expect(errors).toEqual([])
  })

  it('treats anything that is not a coverage map as an empty report', () => {
    const { reporter } = guard(packages)

    reporter.onCoverage(undefined)

    expect(process.exitCode).toBe(1)
  })
})
