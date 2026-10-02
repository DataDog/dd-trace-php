# Required full Linux tracer build comparison

The package child pipeline includes `.gitlab/bazel.yml`. Its two architecture
jobs start after `prepare code`. The amd64 lane runs the focused PHP tests
after its forced architecture probe so test-only repository failures surface
early. Both release lanes then run a forced release build and a fresh-output-base
cache replay. Separate required jobs run the remaining normal tracer variants
after both release lanes finish. This retains forced/cached measurements if the
larger coverage build times out, and keeps its remote actions from contending
with the measured release pair. The report requires the release, coverage, and
legacy baseline artifacts for both architectures. Dependency-override
maintenance pipelines skip this comparison until Bazel consumes the same
overridden dependencies.

`//bazel/products/tracer:ddtrace_fat_<arch>_release` derives 55 products per
architecture from the canonical PHP product matrix: three glibc and two musl
profiles for each PHP minor from 7.0 through 8.5. The lane also builds the
glibc and musl `rust_datadog_php_shared_<arch>_<libc>` products. The separate
`ddtrace_fat_<arch>_remaining` group covers the other 46 normal variants per
architecture, completing the existing 202-product normal tracer matrix.

The existing legacy compile, sidecar, and link jobs measure their real commands
with `tools/bazel/legacy-measure.c`. An outer measurement of each build script
also includes SDK selection, cleaning, copies, and debug compression. The
wrapper uses `wait4` resource usage for the waited process tree and preserves
the command's exit status. It does not change make, Cargo, or linker flags or
concurrency. Each architecture aggregate
validates 55 compile commands, 55 link commands, two sidecar commands, 55 linked
extensions, two standalone Rust DSOs, PHP versions and ABIs, source revisions,
and prepared `VERSION` and bridge hashes. Its elapsed time spans the parallel
job graph; CPU is the sum of measured build processes.

Bazel CI installs the checksum-pinned official Bazel 9.2.0 binary for its runner
architecture. It uses the staging Buildbarn endpoint from `.bazelrc`, explicit execution
platforms, 25 concurrent actions, no local fallback, no local-result upload,
and downloads of all top-level comparison outputs. Fresh output bases isolate forced and cached runs.
The GitLab cache holds only SHA-256 verified repository downloads and has one
writer per architecture. Action results remain in Buildbarn. The report checks
local BEP outputs against remote digest references, requires complete worker
CPU metadata, and compares forced/cached output digests. It publishes HTML,
Markdown, and JSON; a failed evidence check fails the report job.
The two standalone Rust DSOs in each lane must also be ELF shared objects for
the selected architecture, retain debug information, and export the sidecar
entry points required by the C ABI.

## Manually updated dependency images

The package child pipeline exposes manual `bazel deps image: [amd64]` and
`bazel deps image: [arm64]` jobs. Run them after changing dependency locks or
Bazel repository rules. Each uses the prepared source artifact, resolves the
release, remaining, probe, and (on amd64) focused-test repositories with
`--nobuild`, then repeats resolution from fresh output bases with repository
downloads disabled and a separate Bazel user root. The prefetch disables the
shared repository contents cache, so the offline pass must use the download
cache copied into the image. It copies SHA-256 verified downloads, their Bazel
canonical-ID markers, and the pinned Bazel binary into an architecture-specific
OCI image. Repository files are split into smaller layers by SHA-256 prefix.
The image is signed and published
to `registry.ddbuild.io/ci/dd-trace-php/bazel-deps` under a commit and
architecture tag. The manual `bazel deps Nydus: [arch]` jobs convert those OCI
images to signed `-nydus` tags using Datadog's `nydus-convert` wrapper.

The package pipeline pins the Nydus image digests in `.gitlab/bazel.yml`.
After a manual image refresh, update both digests there before merging a
dependency-lock or repository-rule change. This selects
the baked Bazel binary and repository cache, skips the bootstrap and network
probe, and disables the GitLab cache archive. The lane uses
`--repository_disable_download` so missing repository inputs fail rather than
silently fetching new ones. To compare the former path, set
`BAZEL_CI_PRELOADED=0` and override both `BAZEL_CI_IMAGE_*` variables with
`registry.ddbuild.io/images/bazel:dynamic-22.04`. A lock change that introduces
a new download requires a manual image refresh.

Nydus shortens image startup by fetching data on demand. It does not eliminate
Bazel's fresh-output-base analysis, remote action-cache lookups, or required
comparison-output downloads. The report deliberately keeps that fresh replay
as evidence of remote cache reuse. Measure pod-start-to-report time as well as
image-pull time; the first Bazel read may fetch cached repository files from
the Nydus registry layer.

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
