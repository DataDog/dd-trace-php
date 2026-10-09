# Bazel checkpoint — 2026-09-17

This is a reviewed local checkpoint, not a completed Linux product matrix.
It retains the PHP SDK interface, pinned tools and sysroots, generators,
validated dependency adapters, four loader packages, the 202-product normal
fat tracer build matrix, the PHP 8.5 amd64 glibc native tracer gate, and
representative normal profiler archives. `//:php_all`
is the completed SDK interface. `//:extensions_all`, `//:bundles_all`, and
`//:build_all` deliberately report unavailable until their real outputs exist.

## Status

| Area | Status | Evidence / limit |
| --- | --- | --- |
| PHP SDK interface | materialization and local metadata validation pass | `//:php_all` exposes 226 image-derived header SDKs. The OCI lock has 223 imports, one compatible alias, two unavailable records, and 177 metadata documents; 9 extractor and 3 metadata tests pass. This does not execute a target PHP runtime. |
| PHP header compilation | cross-build pass | 223 historical header-compile records completed with exit 0; this is not target-runtime execution. |
| Dependencies and generators | historical native/cross passes | Pinned tools, sysroots, adapters, and generators are retained; reuse their evidence only where their recorded source scope still matches. |
| Loader | cross-build/package pass; historical native glibc smoke | Four platform/libc loader packages are retained. arm64 package production is a cross build; the retained historical native glibc loader smoke is separate evidence. Their direct-filegroup staging path has explicit ELF-validation dependencies. |
| Normal fat tracer matrix | local amd64 and cross-built arm64 pass; native Buildbarn amd64 and arm64 pass | 202 products cover PHP 7.0-7.4 and 8.0-8.5: 101 per architecture. Every DSO, split debug file, and ELF check passed. This is build coverage, not full runtime acceptance. |
| PHP 8.5 tracer | focused fresh native smoke over reused producer closure | The amd64 glibc fat tracer passed load/unload, direct-entry, and sidecar smoke with hash-matched reviewed producers. Other matrix products do not yet have equivalent native runtime gates. |
| Profiler | representative cross-build/archive checks | Four normal representative archives are retained; this does not claim profiler DSO runtime coverage or the full matrix. |
| Product manifest validator | native pass | Six Python regression cases pass. |
| Matrix and product-manifest data | analysis pass | All 226 SDK rows validate and six matrix mutations reject. The 226-row generated manifest SHA-256 is `02cc37a8…ce0ed5b5`, matching historical review; this describes schema bindings, not product completion. |
| Typed product-artifact boundary | analysis pass | The success projection passes; wrong-scope and absent-role projections fail as required. |
| SSI assembler and projection fixtures | native pass | Normalization, deterministic tar output, input-symlink dereference, nested-link rejection, output-link rejection, and the projected payload build pass. |
| Validation-marker action edge | analysis pass | Offline `aquery` shows `artifact_contract_fixture.validated` among the `AssembleSsiPayload` inputs. |
| ASan runtime determinism | amd64 native / arm64 cross-build pass | Completed with detector terminal exit 0 and no differing outputs. |
| WAF remote smoke and teardown | native pass | Forced-uncached native amd64 glibc/musl smoke plus teardown and native arm64 glibc/musl smoke all exit 0 with audited inputs and downloaded artifact hashes. |
| Profiler DSO, AppSec, helper, complete bundles, root aggregates, and full release acceptance | unfinished | `//:extensions_all`, `//:bundles_all`, and `//:build_all` each reject with their intended unavailable reason; the corresponding outputs do not yet exist. |

“Native” means the relevant executable ran on a matching native worker or
host. “Cross-build” means it compiled or inspected the target without
executing it. An analysis result proves declared graph contracts only.

## Reproduce the retained gates

Bootstrap the pinned CLI, then prefetch locked repositories before an offline
run:

```sh
bash tools/bazel/bootstrap-bb.sh
export PATH="$PWD/build/bin:$PATH"
bb fetch //:php_all --config=local-hermetic
bb build //:php_all --config=local-hermetic --repository_disable_download
```

Before running a retained product gate on a fresh checkout, fetch that chosen
target under the same platform configuration, then repeat it with
`--repository_disable_download`. The exact configurations used for checkpoint
gates are retained with their records rather than implied by this short setup
example.

This workspace cannot register Bazel's strict `linux-sandbox` strategy. On a
host that can, use `local-hermetic` unchanged. The focused local packaging
checks used the available processwrapper sandbox only for action execution;
repository downloads and remote execution were disabled:

```sh
python3 bazel/php/validate_product_manifest_test.py
tools/bazel/test-ssi-payload.sh tools/bazel/assemble-ssi-payload.sh \
  tools/bazel/deterministic-tar.sh tar
bb --batch build //bazel/packaging:artifact_contract_binary \
  //bazel/packaging:fixture_manifest_product_binary --build_tag_filters=
bb --batch build //bazel/packaging:artifact_contract_wrong_scope \
  --build_tag_filters= # must fail: scope mismatch
bb --batch build //bazel/packaging:artifact_contract_absent_role \
  --build_tag_filters= # must fail: required role absent
```

The successful validator command exits 0 after six tests. The two final
commands must return nonzero during analysis, respectively reporting the
loader scope mismatch and the missing `archive` role. The SSI script exits 0
after exercising its intentional negative invocations. Checkpoint-time source
digests for those gates are in
[`evidence/checkpoint-2026-09-17/packaging-source-hashes.txt`](evidence/checkpoint-2026-09-17/packaging-source-hashes.txt).
The later processwrapper tree-artifact symlink fix, its current source hashes,
direct fixture exit, and cached real-product packaging recheck are recorded in
[`full-tracer-matrix-2026-09-18/review.json`](evidence/full-tracer-matrix-2026-09-18/review.json).

The exact offline packaging command and result set, including the pinned
repository caches, explicit processwrapper strategy, disabled remote/BES
backends, and terminal log hashes, are in
[`packaging-final-manager-review.json`](evidence/checkpoint-2026-09-17/packaging-final-manager-review.json).
Its successful four-target build uses `--norun_validations`, so the
`artifact_contract_fixture.validated` action input recorded by the companion
[`packaging-validation.json`](evidence/checkpoint-2026-09-17/packaging-validation.json)
is an explicit packaging dependency rather than an automatic validation run.
The current 407-file source and lock snapshot is
[`checkpoint-source-files.sha256`](evidence/checkpoint-2026-09-17/checkpoint-source-files.sha256).

The four retained loader packages were reassembled locally from their reviewed
binary, split-debug, INI, metadata, and version inputs using the current
assembler and deterministic-tar scripts plus the pinned GNU tar 1.35 and gzip
1.14 binaries. All four archives and every member's path, mode, and content
hash exactly match their reviewed archives. The direct native packaging command,
its terminal exit 0 and log hash, tool hashes, input provenance, and resulting
archive hashes are in
[`loader-packaging-recheck.json`](evidence/checkpoint-2026-09-17/loader-packaging-recheck.json).
The copied command and log, historical archive/member reviews, and historical
two-build nondeterminism record are retained beside it. This reuses the
reviewed cross-built loader producers; it does not repeat or claim target
runtime execution.

The retained PHP 8.5 amd64 glibc tracer smoke was freshly replayed with exit 0
over a hash-matched reviewed producer closure. It covered load/unload, direct
DSO entry, sidecar ping, idle teardown, and closure preflight. Its exact replay
command, action query, terminal log and marker hashes, producer and runtime
closure hashes, source scope, and limitations are in
[`tracer-native-replay.json`](evidence/checkpoint-2026-09-17/tracer-native-replay.json).
This is a focused native gate, not a new full product build or full-matrix
acceptance.

The expanded normal tracer matrix was built locally with repository downloads,
remote execution/cache, and BES disabled. The amd64 build compiled and checked
101 targets with exit 0 in 754.489 seconds; the arm64 cross-build checked the
other 101 with exit 0 in 710.959 seconds. The new architecture aggregates
`//bazel/products/tracer:ddtrace_fat_{amd64,arm64}_all` and combined
`//bazel/products/tracer:ddtrace_fat_all` also pass. Exact invocations, source
hashes, action counts, exit codes, log hashes, and limitations are in
[`full-tracer-matrix-2026-09-18/review.json`](evidence/full-tracer-matrix-2026-09-18/review.json),
with deterministic compressed logs and
[`SHA256SUMS`](evidence/full-tracer-matrix-2026-09-18/SHA256SUMS) beside it.
These checks used `processwrapper-sandbox`; the arm64 result is a cross build,
and only the retained PHP 8.5 amd64 glibc product has native load/unload and
sidecar coverage. The outputs are tracer DSO/debug products rather than
complete SSI archives.

The same architecture aggregates were then timed from fresh output roots on
the native Buildbarn staging platforms. Both runs forced execution with
`--remote_accept_cached=false`, disabled local-result uploads, downloaded only
minimal outputs, used the prefetched repository cache offline, disabled BES,
and requested 50 jobs. The amd64 aggregate completed with exit 0 in 5,030.412
seconds (11,413 remote actions; invocation
`f285392a-18b2-4d19-8b36-a48bbe16e857`). The arm64 aggregate completed with
exit 0 in 9,051.585 seconds (11,413 remote actions; invocation
`2f46d051-3792-4769-9846-4aab1aa055d8`). These were respectively 6.667 times
the local amd64 elapsed time and 12.732 times the local arm64 cross-build
elapsed time. Staging usually supplied only two to five workers during product
compilation, and repeated LLVM runtime construction dominated the elapsed
time, so `--jobs=50` did not translate into 50 concurrent remote actions.

The first native arm64 build exposed a platform defect after 6,430 actions:
static musl Rust execution wrappers omitted compiler builtins and failed on
symbols such as `__extenddftf2` and `__addtf3`. The launcher and Rust wrapper
rules now link the architecture-matched LLVM 20.1.4 builtins archive in a
group with libc. An uncached focused native arm64 regression passed both the
launcher and stable clippy-driver wrapper in 473.895 seconds before the full
arm64 rerun. The matching native amd64 launcher and stable clippy-driver
regression then passed in 315.751 seconds; this focused check covers the
launcher change made after the full amd64 timing run. The unsuccessful
attempts, offline dependency prefetch, exact command template, source hashes,
exit codes, invocation IDs, compressed logs, and log hashes are recorded in
[`full-tracer-matrix-2026-09-18/review.json`](evidence/full-tracer-matrix-2026-09-18/review.json).
The failed attempts and prefetch are excluded from the passing run timings.
The remote arm64 gate proves native build-action and ELF-validation execution;
it does not load all products into matching PHP runtimes or prove a local
arm64 build.

The four representative profiler targets were rebuilt locally with the exact
processwrapper command, cached repository settings, and disabled remote/BES
backends recorded in
[`rust-profiler-artifacts.json`](evidence/checkpoint-2026-09-17/rust-profiler-artifacts.json).
That record reports terminal exit 0 after 1,476 actions, full source and
archive hashes, sizes, and ELF-machine counts. Its raw 15 MB log remains in
the ignored recovery archive; its deterministic gzip copy is retained in this
checkpoint as
[`rust-profiler-recheck.log.gz`](evidence/checkpoint-2026-09-17/rust-profiler-recheck.log.gz).
The raw SHA-256 is
`db6c935702f41b4791c04600e9e94b9faa1fca14b409155e9b988645a125291f` and
the gzip digest is in `SHA256SUMS`;
[`rust-validation.sha256`](evidence/checkpoint-2026-09-17/rust-validation.sha256)
verifies it together with the compact records in `build/checkpoint-archives/`.
These are static archive cross-build checks only: neither shared profiler
products nor native profiler loading is accepted, and the arm64 archive is not
native arm64 execution.

The matrix-data gate analyzes both declared matrices without building a product.
Its exact offline command, terminal exit code, source and generated-manifest
hashes, row count, role-binding counts, and historical hash comparison are in
[`matrix-final-review.json`](evidence/checkpoint-2026-09-17/matrix-final-review.json).
It built `//bazel/php:matrix` and `//bazel/php:product_matrix` with exit 0,
validated all 226 SDK rows, and passed all six intentional matrix mutations.
The command's complete build log, exit marker, validation output, and mutation
test output are retained beside that review.

The expected unavailability of `//:extensions_all`, `//:bundles_all`, and
`//:build_all` was checked directly. Their exact command, exit code 1, and the
three intended diagnostics are in
[`unavailable-aggregates-review.json`](evidence/checkpoint-2026-09-17/unavailable-aggregates-review.json).

The exact ASan detector command, including source scope and execution
strategy, is retained in
[`evidence/checkpoint-2026-09-17/asan-runtime-exact-invocation.txt`](evidence/checkpoint-2026-09-17/asan-runtime-exact-invocation.txt).
The directly reproducible shell form is
[`evidence/checkpoint-2026-09-17/asan-runtime-command.sh`](evidence/checkpoint-2026-09-17/asan-runtime-command.sh).
It builds `x86_64_glibc_asan_native_probe` natively and
`_all_aarch64_glibc` as an amd64-hosted cross build, uses
`processwrapper-sandbox`, disables the hermetic Linux sandbox, remote cache,
remote executor, and BES, and keeps repository downloads disabled. Its final
poll completed at `2026-09-17T15:27:30.824Z` with exit code 0 and “No
nondeterminism detected.” The original uncompressed log SHA-256 is
`d11aa04bfc05292491842ad5498f4f372cec3e20b48bb8585e208d56d821c937`;
the deterministic gzip copy, terminal result, source scope, and both backend
audits are checksummed in
[`evidence/checkpoint-2026-09-17/SHA256SUMS`](evidence/checkpoint-2026-09-17/SHA256SUMS).
The ASan runtime continuity record
[`rust-asan-runtime-inputs-unchanged.json`](evidence/checkpoint-2026-09-17/rust-asan-runtime-inputs-unchanged.json)
confirms that all eleven non-`MODULE.bazel` runtime action inputs match the
determinism snapshot. `MODULE.bazel` differs only for reviewed profiler and
source-standard-library declarations and removal of the pending `ring` ASan
annotation, which is outside those runtime action inputs.

The SDK commands, logs, exit codes, and log hashes are in
[`evidence/checkpoint-2026-09-17/sdk-validation.json`](evidence/checkpoint-2026-09-17/sdk-validation.json).
The header-compile review is
[`evidence/checkpoint-2026-09-17/dd-php-header-compile-review.json`](evidence/checkpoint-2026-09-17/dd-php-header-compile-review.json);
its historical source scope must be distinguished from current workspace
sources when reusing it.

Historical foundation acceptance is retained in
[`foundation-evidence-index.json`](evidence/checkpoint-2026-09-17/foundation-evidence-index.json).
It links the retained libunwind, AppSec libxml2, glibc and musl target-curl,
host-PHP generator, OCI offline, PHP-SDK determinism, and LLVM-runtime records
to their original paths, full log hashes, scope, and current target labels.
Those records are historical evidence only; the current 407-file snapshot is
the condition for reusing them. The index lists the exact current targets to
fetch and build for each component, rather than implying an aggregate product
or a new execution.

## WAF diagnosis and staging gate

The glibc 2.17 direct-loader failure was a PIE startup defect: the loader ran
the PIE init array and `__libc_csu_init` ran it again, so constructors could
run twice and corrupt teardown. The toolchain keeps PIE and `Scrt1.o` for
ordinary binaries. Only the WAF validation executables select
`hermetic_nonpie_executable`, which supplies `-no-pie` and `crt1.o`; this
scoped launcher fix is documented beside the feature in
[`toolchains/hermetic_cc_toolchain.bzl`](toolchains/hermetic_cc_toolchain.bzl).
The independent `waf-teardown-repro.cc` checks one constructor before any WAF
API activity, while `smoke.cc` checks it before the allocator and rule
evaluation path. The underlying glibc diagnosis is also recorded in the
[glibc maintainer discussion](https://sourceware.org/legacy-ml/libc-alpha/2014-01/msg00341.html).

The forced-uncached Buildbarn staging runs completed with terminal exit 0:
amd64 ran glibc and musl smoke plus constructor/teardown regression; arm64 ran
glibc and musl smoke. They use native matching workers, disable acceptance
cache and local-result uploads, and disable BES. The exact commands are
[`waf-amd64-command.txt`](evidence/checkpoint-2026-09-17/waf-amd64-command.txt)
and
[`waf-arm64-command.txt`](evidence/checkpoint-2026-09-17/waf-arm64-command.txt).
The audited action inputs and aquery graph hashes are in the architecture
[`upload-audit` records](evidence/checkpoint-2026-09-17/waf-amd64-upload-audit.json),
while downloaded binary, marker, ELF type, execution platform, and log hashes
are in the corresponding
[`amd64`](evidence/checkpoint-2026-09-17/waf-amd64-artifacts-review.json) and
[`arm64`](evidence/checkpoint-2026-09-17/waf-arm64-artifacts-review.json)
reviews. Compact execution audits show all 260 amd64 and 257 arm64 spawns ran
remotely, had no cache hits, and exited 0; see
[`amd64`](evidence/checkpoint-2026-09-17/waf-amd64-execution-review.json) and
[`arm64`](evidence/checkpoint-2026-09-17/waf-arm64-execution-review.json).
The WAF source snapshot is
[`waf-source-snapshot.json`](evidence/checkpoint-2026-09-17/waf-source-snapshot.json);
the compact logs and all evidence-file hashes are retained in
[`SHA256SUMS`](evidence/checkpoint-2026-09-17/SHA256SUMS). A remote arm64 pass
does not prove a local arm64 build.

## Recoverable follow-up work

The tracer matrix expansion recovered from the preserved candidate has now
been integrated and passes its amd64 and arm64 build/ELF gates. The pending
Rust `ring` ASan candidate remains outside this tree in the isolated worktree
`/tmp/dd-php-bazel-pending-rust-ring-profiler`; the pre-candidate Rust worktree is
`/tmp/dd-php-bazel-rust`. The patch archives and snapshots live outside this
commit in the ignored `build/checkpoint-archives/` directory. Their retained
checksum manifests are also copied into this checkpoint as
[`recovery-rust-preservation.sha256`](evidence/checkpoint-2026-09-17/recovery-rust-preservation.sha256)
and
[`recovery-integration-patches.sha256`](evidence/checkpoint-2026-09-17/recovery-integration-patches.sha256).
Verify the archive sets with:

```sh
(cd build/checkpoint-archives && sha256sum -c rust-preservation.sha256)
(cd build/checkpoint-archives && sha256sum -c integration-patches.sha256)
```

Resume in dependency order: complete the Rust ASan boundary (including the
pending `ring` patch); add native runtime acceptance across the tracer matrix;
produce profiler DSOs; complete AppSec and helper behavior; assemble complete
bundles; enable root aggregate targets; then run full release-matrix
acceptance. Re-run any gate whose
declared inputs change rather than reusing its historical result.

The strict local Linux sandbox is unavailable in this workspace. Remote arm64
checks are not local arm64 proof. The two legacy PHP 8.0 Alpine arm64 debug
SDKs have no corresponding locked native runtime, so they have no native
extension-load coverage. Existing CI remains available and is unchanged by this
checkpoint.


## Native LLVM runtimes, September 21

Production LLVM 20.1.4 runtimes now use individually cached Bazel compile,
archive and link actions, with separate bootstrap tools and ASan dependencies.
All 202 tracer DSO/debug/ELF products pass, all four runtime ABI inventories
match the CMake references, and 9,999 runtime files reproduce across fresh
output roots. Matching native workers pass C++ exception/TLS, Rust/proc-macro,
launcher, dependency, ASan and available tracer smoke gates.

The [native runtime report](evidence/native-runtimes-2026-09-21/README.md)
contains exact commands, hashes, forced/cache/no-op/edit measurements and the
concurrency comparison selecting 25 jobs. Source edits execute no unchanged
runtime actions. Top-level Bazel flag changes can still rename transition
output paths across invocations; within-matrix sharing and source-edit reuse
are verified. This extends the earlier build evidence without accepting SSI
bundles or execution of every product under every PHP runtime.
