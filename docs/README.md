# Hostmesh GitHub setup kit

Creates the repository, labels, milestones, issues and a GitHub Project for Hostmesh under an organization (default `hostmesh`), from the roadmap in the v0.1 spec (revision 8).

## What you get

| Item | Details |
|------|---------|
| Repository | `hostmesh/hostmesh`, a **private** monorepo seeded from `repo-seed/` (README, CONTRIBUTING, SECURITY, `LICENSE` (Apache-2.0), `NOTICE`, decision records, a DCO check workflow, issue and PR templates, `.gitignore`, the spec in `docs/spec/`, directory skeleton). Squash merges only, merged branches deleted |
| Labels | Type (`type:*`), area (`area:*`), gates (`gate:alpha`, `gate:beta`) and meta (`spec-change`, `needs-decision`, `blocked`). GitHub's default labels are removed |
| Milestones | One per slice, Slice 0 to Slice 7, with the exit test and planned weeks in the description. Due dates are set when you pass `--start-date` |
| Issues | One epic per slice. Slice 0 and Slice 1 are broken down into detailed, test-first issues. Slices 2 to 7 stay as epics until the slice starts, matching the roadmap principle that scope is settled just before a slice. Alpha and Beta gate checklists |
| Project | An organization Project (`Hostmesh Roadmap`), linked to the repository, with every issue added |

Epics list their child issues as a checklist and, where GitHub supports it, as native sub-issues.

## Before you run it

1. Install the GitHub CLI: https://cli.github.com
2. Sign in and grant the project scope: `gh auth login`, then `gh auth refresh -s repo -s project`
3. You need permission to create repositories and projects in the organization.
4. Python 3.9 or newer, and `git` configured (or the script falls back to your GitHub noreply identity for the first commit).

## Run it

```bash
cd hostmesh-github-setup
python3 bootstrap.py --dry-run                      # review what will happen
python3 bootstrap.py --start-date 2026-10-05        # create everything
```

Useful flags: `--org`, `--repo`, `--visibility private|public`, `--project-title`, `--skip-repo` (repository already exists), `--skip-project`, `--keep-default-labels`.

The script is **idempotent**: labels, milestones and issues are matched by name or title, so it is safe to re-run after an interruption or after editing the JSON in `data/`.

## Due dates

With `--start-date`, each milestone is due at the start date plus its cumulative planned weeks, minus one day: Slice 0 at week 3, Slice 1 at 7, Slice 2 at 14, Slice 3 at 18, Slice 4 at 23, Slice 5 (Alpha) at 29, Slice 6 at 37, Slice 7 (Beta) at 45. These come from the planning calendar in the spec and assume one full-time developer.

## Manual steps afterwards

GitHub does not expose these through the CLI:

1. **Project views.** Create a Board (group by Status), a Table (group by Milestone, showing Labels and Assignees) and a Roadmap (by milestone dates).
2. **Project workflows.** Enable the built-in workflows: auto-add new issues from the repository, set Status to Done when an item is closed, and set Status to Todo when reopened.
3. **Branch protection or rulesets** for `main`, after CI exists (issue "Repository conventions"). Availability on private repositories depends on the organization's plan.
4. **Visibility.** The repository is created private. The license (Apache-2.0) no longer blocks making it public. When you decide, run `gh repo edit hostmesh/hostmesh --visibility public --accept-visibility-change-consequences`, ideally after the trademark and name-reservation issue and a short legal review.
5. **Required checks.** Once the first CI workflows have run, add the DCO check and the pipelines as required status checks.

## Editing the plan

Everything is data: `data/labels.json`, `data/milestones.json` and `data/issues.json`. Edit and re-run. New issues are created; existing ones are matched by title and left as they are (edit those on GitHub).

<!-- Throwaway (issue #105): a change that no suite covers. -->
