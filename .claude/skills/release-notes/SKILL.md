---
name: release-notes
description: >-
  Generate release notes for the current version.
allowed-tools: Bash Read Grep Glob Agent
effort: high
---

# Preparing a minor or major Release

Creating `CHANGELOG.md`.

## Input

Have a look at the `VERSION` file in the dd-trace-php root. Increment the minor version by one, unless the file is already modified in the current worktree.

## Phase 1 — Collect all changes

Go to the milestones of https://github.com/Datadog/dd-trace-php and fetch all pull requests matching the to-be-released version.

Assess all pull requests according to whether they are purely maintainance-related (CI, internal infrastructure, minor test fixes, ... everything not influencing the release artifact itself), or not. If not, categorize them:
 - First check for _Internal_: Anything which does not directly influence user facing functionality (unless it is a crash bugfix).
  - An example would be an addition to dd_trace_internal_fn, a change to telemetry, a protocol change on how to submit to the agent. And more.
 - Then, if not internal, check the nature of the change:
  - _Added_: New feature
  - _Changed_: Touched an existing feature, but isn't a pure bugfix.
  - _Fixes_: Bugfixes

Further check which product category a fix belongs in: Tracer, Profiling or AppSec. Loader (SSI) or general changes affecting multiple products belong to the All products section.

Evaluate for any PR whether it's just a fixup of a previous PR.

Create a `CHANGED-PRs.md` detailing the categorization for each PR with an one sentence summary describing the reason.

## Phase 2 - libdatadog changes

Compare the pinned commit of libdatadog against the libdatadog commit from the latest release.
Find all libdatadog changes and assess whether they're meaningfully adding to a feature.
Important: Ignore those commits which are clearly not related to functionalities exposed by ddtrace. Outright ignore everything not a dependency of the sidecar.

Add a secondary section to `CHANGED-PRs.md`, which includes all non-ignored commits.
Do the same categorization than in Phase 1, and mark those which are clearly encompassed by other PRs from Phase 1.
Keep in mind that most changes in libdatadog are related to the Tracer.

## Phase 3 — Create the changelog

Example structure of a `CHANGELOG.md` to generate:

```
Changelog for older versions can be found in our [release page](https://github.com/DataDog/dd-trace-php/releases).

## All products
### Internal
- Update and shrink build images, migrate to clang 19 #3771

## Tracer
### Added
- Support ApmTracingMulticonfig in dynamic config #3773, #3843

### Fixed
- Improve Symfony http.route resolution performance #3779 (thank you @<name> for the report!)
- Wrap PDO::__construct for signal handling #3786

### Internal
- Fix spawn\_worker trampoline issues DataDog/libdatadog#1844

## Profiling
### Changed
- Improved performance by avoiding a copy of func name when utf8 #3700

## AppSec
### Added
- Enable rust helper on PHP 8.5 #3780 (can be disabled with `DD_APPSEC_HELPER_RUST_REDIRECTION=false`)
```

### Notes
- Thank users who contributed a PR, or found an issue and were very insightful.
- When a major feature was added, information about enabling/disabling or migration information can be appended.
- Avoid duplication of topics, group them together, enumerating the PRs. Only include libdatadog PRs in this enumeration if all of the PRs are from libdatadog.
- If necessary, edit the PR titles to be full sentences and more useful for the reader.
- Remove all unused sections.

## Phase 4 - Confirm the uncertainities

Present the `CHANGELOG.md` to the user.

When uncertain about the categorization of some PRs, use the AskUserQuestion tool and ask the user to categorize himself, with a your preference and reason why.
Otherwise, we're done.
