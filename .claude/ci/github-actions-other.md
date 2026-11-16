# GitHub Actions — PR Automation and Release Tooling

## CI Jobs

**Source:**
- `.github/workflows/auto_check_snapshots.yml` — snapshot change summary
- `.github/workflows/auto_label_prs.yml` — PR auto-labeling
- `.github/workflows/auto_add_pr_to_miletone.yml` — milestone automation
- `.github/workflows/add-asset-to-gh-release.yml` — release asset upload
- `.github/workflows/update_latest_versions.yml` — version update automation
- `github-actions-helpers/build.sh` — .NET build script used by snapshot/label/milestone workflows

Profiler tests (`prof_asan`, `prof_correctness`) are documented in
[github-actions-profiler.md](github-actions-profiler.md).

| CI Job | Runner | Trigger | What it does |
|--------|--------|---------|--------------|
| `Check snapshots / check-snapshots` | `ubuntu-24.04` | `pull_request` | Summarizes snapshot changes in a PR comment |
| `Label PRs / add-labels` | `ubuntu-24.04` | `pull_request` | Auto-assigns labels to PRs based on changed files |
| `Auto add PR to vNext milestone / add_to_milestone` | `ubuntu-24.04` | `pull_request` (closed+merged to master/main) | Assigns merged PRs to the vNext milestone |
| `Add assets to release / add-assets-to-release` | `ubuntu-8-core-latest` | `workflow_dispatch` | Downloads `packages.tar.gz` and uploads contents to a GitHub release |
| `Update Latest Versions / update-latest-versions` | `ubuntu-24.04` | `schedule` (Mon 06:30 UTC) or `workflow_dispatch` | Runs `tests/PackageUpdater.php` and opens a PR updating pinned test dependency versions |

## What They Do

### auto_check_snapshots

Uses .NET (`dotnet-7.0.101`) to build and run `SummaryOfSnapshotChanges` from
`github-actions-helpers/`. Posts a PR comment summarizing any snapshot file
changes (requires `fetch-depth: 0` for diff against base branch).

### auto_label_prs

Uses .NET to run `AssignLabelsToPullRequest`. Labels are applied based on which
files were changed.

### auto_add_pr_to_miletone

Runs only when a PR is merged into `master`/`main` and the title does not start
with `[Version Bump]`. Uses .NET to run `AssignPullRequestToMilestone`, which
assigns the PR to the next open milestone.

### add-asset-to-gh-release

Manual-only (`workflow_dispatch`). Takes two inputs: a URL to `packages.tar.gz`
and a release version string. Downloads the tarball, extracts it, and uploads
all files under `build/packages/` to the specified GitHub release using `gh
release upload --clobber`.

### update_latest_versions

Weekly cron (Monday 06:30 UTC) or manual trigger. Installs the latest
dd-trace-php release, runs `make composer_tests_update` and `php
tests/PackageUpdater.php`, then opens a PR via
`peter-evans/create-pull-request` on a branch `update-latest-versions`.

## Local Reproduction

### Automation workflows (snapshots, labels, milestones, version updates)

These workflows depend on GitHub API tokens, .NET tooling, and the PR event
context. They are not practical to reproduce locally. If a failure occurs,
inspect the workflow run log on GitHub Actions directly.

## Gotchas

- The .NET-based automation workflows (`auto_check_snapshots`, `auto_label_prs`,
  `auto_add_pr_to_miletone`) all use dotnet 7.0.101 and share the
  `github-actions-helpers/build.sh` entry point with different task names.
- `auto_add_pr_to_miletone.yml` has a typo in its filename (missing an "s" in
  "milestone") — this is intentional/historical; do not rename it.
