---
name: check-ci
description: >-
  Monitor GitLab CI and GitHub Actions for this repo: start check-ci, tail
  results with ci-watch, investigate failures, and report. Use when the user
  asks to check, watch, or monitor CI, or to see whether a pipeline passed.
argument-hint: "[--commit <ref> | --pipeline <id>] [--jobs <pat1,pat2>] [--list-jobs]"
allowed-tools: Bash Read Grep Glob Agent TaskCreate TaskUpdate TaskStop mcp__speak_when_done__speak
effort: high
---

# Check CI

Monitor GitLab CI and GitHub Actions until all jobs finish, then investigate
any failures and report results.

When `--commit` is used (or defaulting to HEAD), both GitLab pipelines and
GitHub Actions workflow runs are monitored. GitHub monitoring requires
`ddtool auth github login --org DataDog`; if unavailable, a warning is
printed and only GitLab is monitored. `--pipeline <id>` is GitLab-only and
skips GitHub.

## Input

`$ARGUMENTS` may contain any combination of:
- `--commit <ref>` — git ref to resolve (default: HEAD); monitors GitLab +
  GitHub
- `--pipeline <id>` — specific GitLab pipeline ID (skips GitHub monitoring)
- `--jobs <pat1,pat2>` — comma-separated substring patterns (case-insensitive);
  monitor ONLY the jobs whose name contains any pattern. In monitoring mode the
  run finishes as soon as the matched subset is terminal (rather than waiting for
  the whole pipeline); with `--list-jobs` the snapshot shows only matched jobs.
  Applies to both GitLab jobs and GitHub Actions workflow jobs. Omit for
  whole-pipeline monitoring (the default).
- `--list-jobs` — quick snapshot mode (no monitoring)

If no `--commit` or `--pipeline` is given, default to `--commit HEAD`.

## Quick mode — `--list-jobs`

Run synchronously and exit immediately:

```bash
.claude/ci/check-ci --commit <ref> --list-jobs
```

Prints all jobs grouped by pipeline (GitLab) and workflow run (GitHub
Actions) with their status. Print the table to the user and stop. Do not
continue to the monitoring steps.

## Full monitoring mode

### Step 1 — Start check-ci in the background

```bash
PYTHONUNBUFFERED=1 .claude/ci/check-ci [OPTIONS]
```

- Use `run_in_background: true` in Bash tool invocation. Do NOT append `&` or
  redirect output.
- The Bash tool returns immediately with an output file path like
  `/path/to/tasks/<id>.output` ("Output is being written to ..." in the tool
  invocation output). Note this path — it is required in Step 2. This file path
  will be referred to as `OUTPUT_FILE` henceforth.
- Default options if the user provided none: `--commit HEAD`.
- You may also pass `--max-failures 50` (default) and
  `--timeout 7200` (default, 2 h).
- To watch only a named subset of jobs, add `--jobs "<pat1>,<pat2>,..."`. The
  run then finishes as soon as those matched jobs are terminal, instead of
  waiting for the whole pipeline. Example — watch the three Windows `test_c`
  version variants:
  ```bash
  PYTHONUNBUFFERED=1 .claude/ci/check-ci \
    --jobs "windows test_c: [7.2],windows test_c: [7.3],windows test_c: [7.4]"
  ```

### Step 2 — Start ci-watch in the background

```bash
.claude/ci/ci-watch [--start-offset N] OUTPUT_FILE
```

- `OUTPUT_FILE` must be the output file from the check-ci task above.
- Use `run_in_background: true`.
- You are notified when ci-watch exits through a task notification. While it
  runs, you may do other work.
- On exit, ci-watch always prints `RESUME_OFFSET: <N>`. Record it for re-runs.

ci-watch exit codes:
| Code | Meaning |
|------|---------|
| 0 | All pipelines completed — no failures |
| 1 | One or more `FAILED:` lines detected |
| 2 | Stale — no new output for 5 minutes |
| 3 | check-ci timed out |

### Step 3 — Speak and act on the result

**Immediately after ci-watch exits**, call
`mcp__speak_when_done__speak(message="...")` (the first time, you'll need to do
invoke `ToolSearch("select:mcp__speak_when_done__speak")`:
- Exit 0 → "All CI jobs passed"
- Exit 1 → "<N> CI jobs failed" (count with `grep "^FAILED:" OUTPUT_FILE | wc
  -l`)
- Exit 2 or 3 → "CI monitor timed out"

Then choose the appropriate action:

#### All jobs passed (exit 0)

Report success to the user and stop.

#### Failures detected (exit 1)

1. List the failed jobs:
   ```bash
   grep "^FAILED:" OUTPUT_FILE
   ```
   The output directory is `/tmp/gitlab_<pipeline_id>/`. Logs are at:
   - `fail_logs/<job_id>.log` — GitLab job traces
   - `gh_fail_logs/gh_<job_id>.log` — GitHub Actions job logs
   GitHub entries in `failure.txt` are prefixed `[GH]`.

2. Read each failure log and diagnose the root cause. Look for:
   - Compile errors or linker failures
   - Test assertion failures (include the failing test name and diff)
   - Infrastructure/flakiness signals (timeout, network, Docker pull failures,
     OOM) — mark these as flaky rather than real failures.

   Except you don't need to go through of them if it becomes evident it's
   unnecessary.

3. Report findings grouped by root cause.

4. **Fix and push only when all three conditions hold:**
   a. The user explicitly asked you to fix CI failures.
   b. You have made changes to address the failures.
   c. The current branch has an upstream remote branch.
   If any condition is missing, stop and report instead.

   When all three hold: commit the fix, push, then go back to Step 1
   to re-monitor.

   If possible, before attempting a fix, try to reproduce the failure locally.
   Check @.claude/ci/index.md for instructions. Then attempt your fix and rerun
   to confirm the fix resolves the problem.

#### Stale or timed out (exit 2 or 3)

Re-run ci-watch with `--start-offset <RESUME_OFFSET>` (Step 2) to
resume watching from where you left off. If check-ci itself has also
exited, restart from Step 1.

#### Keep watching (user wants to continue after investigation)

Re-run ci-watch with `--start-offset <RESUME_OFFSET>` (back to Step 2).

## Downloading artifacts

Use `tooling/bin/download-artifacts` to fetch build outputs from CI jobs
(e.g., compiled extensions, SSI loader, datadog-setup.php). Useful when
investigating a failure that produced an artifact worth inspecting locally.

## Rules

- Never push unless the user explicitly asked for it. See the global
  instruction "Do not push to git remotes unless explicitly asked to."
- Flaky jobs (known to be intermittent, unrelated to the current
  changes) should be noted but not treated as real failures requiring
  a fix. However, to confirm that a test is failure you should look for
  similar failures in the merge base.
- `GITLAB_PERSONAL_ACCESS_TOKEN` is already set in the environment —
  do not re-export it.
- Raw job logs can also be fetched directly. For Gitlab:
  ```bash
  curl -s -H "PRIVATE-TOKEN: $GITLAB_PERSONAL_ACCESS_TOKEN" \
    "https://gitlab.ddbuild.io/api/v4/projects/355/jobs/<JOB_ID>/trace"
  ```
