# CI Documentation Improvement Loop

This file describes a repeatable process for keeping CI reproduction docs
accurate and complete by running jobs locally and harvesting the gap between
what the docs say and what actually happens.

## The Loop

### Step 1 — Run the job locally via subagent

Use the `/subagent_log` skill to launch a general-purpose subagent that reads
the CI docs and runs the job. The subagent prompt should be minimal and
self-contained — just name the job (e.g. "Execute locally the 'prof-correctness
(8.1, zts)'; do not give any other context or clues"). The subagent will read
the relevant CI docs itself and figure out what to run.

The `/subagent_log` skill wraps the Agent tool call, captures timing and token
usage, and returns a link to the HTML log.

### Step 2 — Ask the subagent what was missing

After the subagent finishes, resume it (via `SendMessage`) with:

> What new information about running the tests did you find out that was not in
> `@.claude/ci/<relevant-doc>.md` (or `index.md`)? Suggest changes to speed up
> further evaluations.

The subagent has the full context of what it actually ran, what failed, what it had to
work around, and what the docs said — so it can produce a precise diff of reality vs.
documentation.

### Step 3 — Apply the changes

Take the subagent's output and update the relevant docs:

- **Job-specific gaps** → update the job-group `.md` file (e.g.
  `github-actions-profiler.md`)
- **Gaps that apply across all job groups** → update `index.md` (e.g. NTS vs
  ZTS crash behaviour, `--php` variant semantics, cache naming conventions)
- **Speed-up tips** → add inline in the relevant section, not in a separate
  "tips" block

Prefer editing existing sections over appending new ones — the goal is accurate
prose, not an ever-growing list of addenda.

See `@.claude/ci/meta-job-group-doc.md` for a description of the format of the
job docs.

## What Makes a Good Gap Report

Ask the subagent to be concrete: exact commands, environment variables, file
paths, timing observations. Vague observations like "the docs could be clearer"
are not actionable.

Good gaps to capture:

- Commands that fail verbatim from the docs and require a fix
- Dependencies (packages, files, env vars) that must be present but are not
  mentioned
- Paths or filenames that differ between the docs and reality
- Steps that are described as necessary but turn out to be no-ops (e.g. CI
  checks `.lz4` but profiler emits `.zst` — the check is vacuously true)
- Speed differences large enough to matter (e.g. `EXECUTION_TIME=3` saves 50+
  seconds per run; `DD_PROFILING_LOG_LEVEL=warn` vs `trace` measurably affects
  throughput)

Not worth capturing:

- Stylistic preferences
- Observations that are already implied by the docs
- Flaky test outcomes with no actionable fix

## Fidelity to CI

Reproduction scripts in the docs must match what CI actually does as closely as
possible.  The source of truth is the CI job definition (e.g.
`.gitlab/generate-package.php`, `.github/workflows/`).  When writing or updating
a "Reproducing Locally" example:

- Read the real `before_script` / `script` and replicate the same commands,
  environment variables, and package installs — do not invent steps or guess
  what might be needed.
- Only deviate where the local environment genuinely differs (e.g. mounting
  artifacts from a host path instead of relying on GitLab artifact download,
  or using `dockerh` instead of a bare CI runner).
- If a step looks unnecessary, verify by checking the CI definition before
  removing it — it may exist for a non-obvious reason (e.g. `apk add
  ca-certificates` works around an Alpine TLS issue).

## Example

**Job run:** `prof-correctness (8.1, zts)` via `/subagent_log`

**Gaps found and applied:**

| Gap | Where fixed |
|-----|-------------|
| `--php zts` required for ZTS builds (NTS headers crash ZTS PHP) | `index.md` — `--php` section |
| `parallel` PECL needs `libpcre2-dev` + GitHub URL for v1.2.7 | `github-actions-profiler.md` |
| `parallel.so` not persisted in overlay cache — copy to project dir | `github-actions-profiler.md` |
| Image tag must match PHP version under test | `github-actions-profiler.md` |
| `cargo rustc` must run from `profiling/` subdir | `github-actions-profiler.md` |
| Output path in docs differed from CI workflow path | `github-actions-profiler.md` |
| `EXECUTION_TIME=3` applies to all time-based tests, not just `allocations` | `github-actions-profiler.md` |
| `DD_PROFILING_LOG_LEVEL=warn` recommended (trace slows execution) | `github-actions-profiler.md` |
| "No profile" check is vacuously true (`.lz4` vs `.zst`) | `github-actions-profiler.md` |
