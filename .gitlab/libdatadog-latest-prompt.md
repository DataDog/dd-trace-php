# libdatadog Continuous Integration Analysis

You are analyzing whether the latest libdatadog is API-compatible with dd-trace-php and adapting the code if necessary.

## Your task

1. Read the CI summary and failure traces from the artifacts directory
2. Classify each failure as: API change to fix / libdatadog bug to report / flaky test
3. For API changes: edit the dd-trace-php Rust source files to adapt
4. Write a comprehensive REPORT.md summarising what you found and did

## Important constraints

- You **cannot** run cargo, make, or any other build/test commands. There is no Rust compiler available. Reason about changes from the error messages and source code alone.
- Do **not** edit anything under the `libdatadog/` source directory.
- Do **not** skip existing code just to satisfy tests, do not comment out failing tests, do not downgrade trait bounds or version requirements to hide incompatibilities.

## Input files to read first

- `tmp/artifacts/ci_summary.txt` — list of all failed jobs across ALL sub-pipelines (tracer, appsec, profiler, shared, package) plus trace tails for compilation/build failures
- `tmp/artifacts/traces/` — directory of full trace files for compilation failures (each file is named after the job and contains the last ~15 KB of its log)
- `tmp/artifacts/libdatadog_changelog.txt` — git log of new commits since the pinned SHA
- `tmp/artifacts/all_failures.json` — structured list of every failed job

The **new** libdatadog source tree is in the `libdatadog/` directory relative to the project root.

## Classification rules

### Fix (API changes in libdatadog)

These are expected during libdatadog development and should be adapted:

- A type, function, method, or module was renamed → update all call sites
- A function signature changed (new parameters, changed return type) → update call sites
- A struct gained required fields → add them with sensible defaults
- A struct lost fields → remove all references
- A trait gained required methods → implement them
- An enum variant was renamed or added → update match arms and constructors
- A public re-export moved to a different path → update `use` statements

Look up the new API in the `libdatadog/` source tree to get the correct names and signatures before editing.

### Report but do NOT fix (libdatadog bugs or design issues)

Do not try to work around these — report them in `tmp/REPORT.md` and leave the code broken:

- A panic or unexpected behaviour coming from *inside* libdatadog (not from our code calling it incorrectly)
- A regression where functionality that previously worked no longer does (and there is no obvious new API to call instead)
- An API change so large it would require significant redesign of our architecture. But first give it a try! Maybe it's not that bad.

### Ignore (test flakiness or unrelated failures)

- Failures that mention timing, sleep, race condition, or `flaky`
- Failures in tests completely unrelated to libdatadog (e.g., pure PHP test infrastructure)
- A single failure in a test where the log shows an external resource issue

## Writing REPORT.md

Always create/overwrite `tmp/REPORT.md` in the project root. Use this structure:

```markdown
# libdatadog Integration Report

**libdatadog SHA**: <full SHA from Environment section>
**Analysis date**: <today>

## Overall status

<!-- One of: ✅ Clean update | ⚠️ Adapted (API changes fixed) | ❌ Blocking issues remain -->

## Build & test summary

<!-- What passed, what failed, overall picture across all sub-pipelines -->

## Non-trivial changes made

<!-- For each file changed: path, what changed, and why -->
<!-- If nothing was changed: "No code changes required." -->

## Identified libdatadog issues

<!-- Bugs or design problems in libdatadog that we should NOT work around.
     For each: symptom, affected libdatadog code path, why it is a libdatadog bug. -->
<!-- If none: "None identified." -->

## Flaky / ignored failures

<!-- Failures that appear flaky or unrelated to libdatadog changes. -->
<!-- If none: "None." -->
```

Write the report even if the CI passed cleanly — status is "✅ Clean update" and sections
are brief.
