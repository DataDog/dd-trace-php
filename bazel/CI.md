# Required full Linux tracer build comparison

The package child pipeline includes `.gitlab/bazel.yml`. Its two architecture
jobs start after `prepare code`, and each runs a forced architecture probe,
a forced release build, a fresh-output-base cache replay, the remaining normal
tracer variants, and (on amd64) the focused PHP tests. Failures in any step
fail the job. The report depends on both Bazel lanes and both architecture
baseline aggregates. Dependency-override maintenance pipelines skip this
comparison until Bazel consumes the same overridden dependencies.

`//bazel/products/tracer:ddtrace_fat_<arch>_release` derives 55 products per
architecture from the canonical PHP product matrix: three glibc and two musl
profiles for each PHP minor from 7.0 through 8.5. The lane also builds the
glibc and musl `rust_datadog_php_shared_<arch>_<libc>` products. The separate
`ddtrace_fat_<arch>_remaining` group covers the other 46 normal variants per
architecture, completing the existing 202-product normal tracer matrix.

The existing legacy compile, sidecar, and link jobs measure their real commands
with `tools/bazel/legacy-measure.c`. The wrapper uses `wait4` resource usage for
the waited process tree and preserves the command's exit status. It does not
change make, Cargo, or linker flags or concurrency. Each architecture aggregate
validates 55 compile commands, 55 link commands, two sidecar commands, 55 linked
extensions, two standalone Rust DSOs, PHP versions and ABIs, source revisions,
and prepared `VERSION` and bridge hashes. Its elapsed time spans the parallel
job graph; CPU is the sum of measured build processes.

Bazel uses the staging Buildbarn endpoint from `.bazelrc`, explicit execution
platforms, 25 concurrent actions, no local fallback, no local-result upload,
and full output downloads. Fresh output bases isolate forced and cached runs.
The GitLab cache holds only SHA-256 verified repository downloads and has one
writer per architecture. Action results remain in Buildbarn. The report checks
local BEP outputs against remote digest references, requires complete worker
CPU metadata, and compares forced/cached output digests. It publishes HTML,
Markdown, and JSON; a failed evidence check fails the report job.
The two standalone Rust DSOs in each lane must also be ELF shared objects for
the selected architecture, retain debug information, and export the sidecar
entry points required by the C ABI.

The report separates elapsed time, runner CPU, Buildbarn worker CPU, total
build-process CPU, remote cache hits and misses, queue time, and requested runner
core-seconds. Historical CPU attached to cache hits is excluded. Queue time is
elapsed waiting, not CPU. Shared source preparation and the temporary cost of
parallel migration lanes are shown separately. Savings are potential until the
equivalent legacy work is retired. The comparison excludes Windows, ASan,
profiler, AppSec, installer packaging, and whole-pipeline savings.

Local checks are `python3 tools/bazel/ci_test.py`,
`python3 tools/bazel/runtime-log-metrics_test.py`, and shell/C syntax checks.
Acceptance also requires both live architecture probes, both complete release
builds and cache replays, the remaining normal variants, the focused PHP tests,
extension ABI/export/debug checks, standalone-library compatibility, and one
successful real PR pipeline with complete baseline and RBE report artifacts.
