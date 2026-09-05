# Diagnosing CI Failures & Flakiness

Transferable triage techniques to apply before proposing a fix for a failing or
flaky CI job. For reproducing a specific job locally, see the group docs linked
from [index.md](index.md).

## Determine the failure mode first: hang vs test failure

Before blaming any FAIL/diff line in the log, check the job **duration**. A
duration equal to the job timeout (e.g. `windows test_c` dies at ~3601s = the
1 h timeout) means the job died from a **hang/timeout**, not from the assertion
failures printed earlier. Those FAIL lines may be real, but are separate,
non-fatal issues.

- Grep the tail for a hang signature (`execution took longer than`,
  `Failed to terminate process`) vs an assertion `FAIL`/diff (a test bug).
- **`run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP` suppresses PASS
  output.** So the last log line is NOT necessarily the hang point — it is just
  the last *reported* (failed/skipped) test before a run of silently-passing
  ones. To find the real hang point, add `-v` (sets `$DETAILED`, printing
  `TEST <path>` before each test): the last `TEST` line that never gets a
  result is the culprit. Confirm determinism against a second matrix variant.
- Timed-out jobs skip `after_script`, so they upload only `job.log` (no
  `sidecar.log`/dumps). A passing sibling version is both the comparison
  baseline and the source of the artifacts the failing job never emitted.

## All-versions deterministic flip with no source change = mutable image

If a test flips green→red **deterministically across every PHP version/SAPI**
with no corresponding source/commit change, suspect a **mutable CI image tag
being republished** under the same tag (the `bookworm-{N}` images are rebuilt
in place with `docker buildx bake --no-cache --pull`), not a code regression.
Verify the mechanism empirically before fixing:

- Check that pipelines on the *same* sha previously passed — if so, the trigger
  is the image republish, not the commit.
- Diff green-vs-red job **artifacts** (`[ddtrace]` logs, sidecar data) rather
  than theorizing about log levels / worker recycling.
- Watch for env vars already forced by the Makefile test target (e.g.
  `run_tests_debug` forces `DD_TRACE_DEBUG=1`), so a "config" fix isn't a silent
  no-op.

A slower rebuilt image commonly loses a pre-existing **retrieval-window race**:
e.g. a `/telemetry` poll that stops as soon as it sees `spans_created` can miss
the later batch carrying `logs_created`. Fix the race (accumulate the awaited
metrics across requests); don't relax the assertion.

## Retry-trait cascade masks the real failure

Tests using the persistent-server retry trait
(`tests/Common/RetryTraitVersionGeneric.php`) re-run the whole test against an
already-warmed server on failure, and **only the last attempt's exception is
reported**. The reported failure can therefore be a downstream symptom of the
first attempt's real break:

- One-shot-per-server-lifecycle metrics (e.g. `logs_created`, emitted only on
  the first telemetry lifecycle of a server process; zero-valued metrics aren't
  sent) fail on every *warmed* retry — so a reported first-window `logs_created`
  failure is often really a later-window break cascading through the retry.
- To see the true cause, temporarily `fwrite(STDERR, ...)` the swallowed
  exception in the trait's `catch` block.
- Hard-coded dependency-version assertions on `^x.y` fixtures break silently
  when a fresh `composer update` resolves a new upstream patch release; verify
  what actually resolves in the container with `composer show <pkg>`.

## Root-cause sidecar error logs — don't suppress them

Recurring sidecar error logs (`Failed synchronously flushing traces:
Kind(TimedOut)`, `connection reset by peer (os error 104)`, `The sidecar
transport is closed. Reconnecting...`) are **symptoms** of a real flush-timeout
/ transport-reset problem. Do NOT green a flaky test by silencing them
(`DD_TRACE_LOG_LEVEL=off`, per-target `datadog_ipc=off,datadog_sidecar=off`, or
grep-ignoring the line). Investigate why the flush times out / the IPC transport
resets and fix that root cause; silencing leaves the defect to resurface
elsewhere.

## Merge gate: no `allow_failure` for known-flaky jobs

Known-flaky jobs are NOT hidden with `allow_failure`. A single `merge-gate` job
(last stage, `when: always`, no skip rules) runs `.gitlab/merge-gate.sh`, which
collects every failed job across the parent + child pipelines via the GitLab API
(`/pipelines/:id/bridges` → downstream ids → `/pipelines/:id/jobs?scope[]=failed`)
and matches each against glob patterns in `.gitlab/flaky-jobs.txt`. A failure
matching no pattern fails the gate; matches are treated as known-flaky and
ignored. Branch protection requires only the single `merge-gate` check.

To mark a job flaky, add its glob to `.gitlab/flaky-jobs.txt` (matrix jobs reduce
to `base:*`, so any failing version marks all versions flaky; non-matrix jobs
stay exact). Never reach for `allow_failure`.
