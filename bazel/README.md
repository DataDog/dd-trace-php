# Hermetic Linux Bazel build

This directory contains the additive Bazel build for Linux amd64 and arm64,
with glibc 2.17 and dynamic musl targets. Build actions use checksum-locked
execution tools, explicit target sysroots, and LLVM 20.1.4 target runtimes.
Repository setup is the only phase that downloads inputs.

LLVM target runtimes now use individual Bazel compile/archive/link actions.
See [runtime builds and RBE measurements](dependencies/llvm_runtimes/README.md)
for component labels, CMake reference targets, validation and cache modes.

The current PHP boundary is a header SDK. `//:php_all` materializes every
matrix SDK as a declared relocatable output from immutable OCI profile layers.
Each output contains the complete installed header tree, effective ABI
metadata, provenance, and a relocatable `php-config`. The historical PHP
source version remains provenance; the provider reports the patch version and
API observed in the image. Two unpublished PHP 8.0 Alpine arm64 debug SDKs
are derived from the matching arm64 NTS/ZTS header sets with a declared
`ZEND_DEBUG` transform. No target PHP runtime is compiled or executed.
Their ARM musl compile and ABI layout checks pass, but no matching legacy ARM
debug runtime exists in the locked image inventory, so native extension-load
coverage is not claimed for those two profiles.

The staged gates are:

1. `//:stage1`: pinned repositories, execution platforms, and matrix data.
2. `//bazel/stages:remote_arch_{amd64,arm64}`: native worker architecture.
3. execution-tool, sysroot, LLVM compiler, and target-runtime probes.
4. `//:php_all`: all configured PHP header SDK outputs.
5. native Datadog libraries and product link/runtime checks.
6. packaging, relocation, offline, incremental, and full determinism checks.

Product and bundle aggregate targets are enabled only after their real outputs
exist. A passing SDK target is not a claim that tracer, profiler, AppSec,
loader, sidecar, or SSI artifacts are complete.

The reviewed local checkpoint, its retained evidence, the exact gate commands,
and continuation order are recorded in [CHECKPOINT.md](CHECKPOINT.md).

## Focused PHP tests

The complete tracer extension PHPT tree can be run with the PHP 8.5.8RC1
`run-tests.php` from the existing checksum-locked PHP source repository:

```sh
bb test //bazel/tests:phpt_all --config=local-hermetic \
  --test_tag_filters= --test_output=errors
# Limit the same test to one file when investigating a failure:
bb test //bazel/tests:phpt_all --config=local-hermetic --test_tag_filters= \
  --test_arg=tests/ext/active_span.phpt --test_output=errors
```

This target is tagged `manual` so `bb test //...` does not accidentally run an
unsafe corpus on a processwrapper host. Override the default `-manual` filter
only when explicitly invoking this label. It declares and runs every
`tests/ext/**/*.phpt` (717 at this checkpoint), including `EXPECTF`,
`EXPECTREGEX`, `SKIPIF`, `CLEAN`, and fixture
files. It copies the declared tree to test scratch space so the upstream
runner can write temporary files without modifying source inputs. The pinned
OCI PHP and its declared library closure run the harness and every child PHP
process; results and diffs appear in Bazel test logs, and JUnit output is
written to Bazel's undeclared test outputs.
Tests for PHP versions or extensions absent from this locked runtime may skip
or fail; the run reports those outcomes rather than dropping the files.

This full-corpus target requires an isolated Linux mount namespace because
some tests touch `/var/run/datadog` or invoke `sudo`. It **fails closed** under
the checkout's `local-processwrapper` default; the explicit
`--test_env=PHPT_UNSAFE_ALLOW_PROCESSWRAPPER=1` override risks host-side effects
and must not be used as hermetic evidence. Disable the checkout-local fallback
before running with `local-hermetic`. Upstream PHP's `run-tests.php` invokes
`/bin/sh` for `proc_open` commands, and some tests invoke external programs,
so even an isolated run is not yet a fully hermetic acceptance gate.
The currently locked image has PHP CLI but not PHP CGI, so the PHP runner skips
CGI-only tests. The other 426 repository PHPT files (AppSec, profiling,
loader, and other `tests/` subtrees) are not included: they need their matching
DSOs, runtimes, or service dependencies before a meaningful execution suite
can be added.

For investigating a *reviewed, single* PHPT on a checkout where Linux sandbox
namespaces are unavailable, use the explicit override with `--test_arg`:

```sh
bb test //bazel/tests:phpt_all --test_tag_filters= \
  --test_arg=tests/ext/read_c_configuration.phpt \
  --test_env=PHPT_UNSAFE_ALLOW_PROCESSWRAPPER=1 --test_output=all
```

Do not use that override for the complete corpus. The full suite has not been
timed to completion on this checkout.

`//bazel/tests:php_tests` runs four selected, isolated extension PHPT cases
with the locked PHP 8.5 amd64 glibc CLI and normal fat tracer. The PHPT runner
supports only `TEST`, `FILE`, `EXPECT`, `ENV`, and `INI`: unsupported sections
fail rather than silently skip. It does not claim coverage for the rest of the
PHPT corpus, PHPUnit/Composer suites, other PHP versions, or other ABIs.

`//bazel/tests:http_extension_test` uses `rules_itest` to provision a fresh
loopback PHP HTTP service with a dynamically assigned port and checks that
the tracer loads in the HTTP SAPI. It requires a sandbox that supports
loopback sockets. Both tests launch the OCI-locked PHP via its declared ELF
loader and library paths, reject resolved dependencies outside the runfiles
closure, verify mapped ELF libraries during a real PHP invocation, and clear
inherited runtime environment variables.

The shared Bazel configuration builds `rules_itest`'s Go service manager in
pure-Go mode so its standard library does not invoke an undeclared host `cc`.

```sh
bb test //bazel/tests:php_tests --config=local-hermetic --repository_disable_download
```

The checkout-local `local-processwrapper` override does **not** isolate
network or host filesystem access and is only a development check. For a
hermetic acceptance run, disable that override and run `local-hermetic` on
a host with Linux sandbox namespaces or execute on a suitably isolated
remote worker. Loopback is needed by `rules_itest`; outbound network access
must remain disabled. Fetch the pinned modules and OCI layers before using
`--repository_disable_download`.

## Tracer product matrix and focused native gate

The normal fat tracer matrix publishes 202 products: 101 amd64 and 101 arm64
products spanning PHP 7.0-7.4 and 8.0-8.5. Every product includes the stripped
DSO, split debug file, and ELF-validation marker. Build one architecture or the
whole matrix with:

```sh
bb build //bazel/products/tracer:ddtrace_fat_amd64_all
bb build //bazel/products/tracer:ddtrace_fat_arm64_all
bb build //bazel/products/tracer:ddtrace_fat_all
```

The arm64 command is a cross build on an amd64 host. The retained PHP 8.5
amd64 glibc target additionally has a native load/unload and sidecar smoke
gate:

```sh
bb build //bazel/products/tracer:ddtrace_fat_amd64_glibc_php85 \
  //bazel/products/tracer:ddtrace_fat_amd64_glibc_php85_native_smoke
```

The four representative normal profiler archive checks are:

```sh
bb build //:rust_profiler_php71_amd64_glibc_nts \
  //:rust_profiler_php71_amd64_glibc_debug_zts \
  //:rust_profiler_php85_arm64_glibc_nts \
  //:rust_profiler_php85_amd64_musl_nts
```

The profiler targets are accepted only by the exact local processwrapper
cross-build/archive invocation recorded in [CHECKPOINT.md](CHECKPOINT.md);
the short target command above does not itself select that execution strategy.
The accepted check does not execute the arm64 archive or accept profiler DSO
runtime loading.
The tracer aggregate proves compilation, split-debug production, and ELF
contracts for the declared matrix. It does not run every product under its
matching PHP runtime. Exact commands, exits, source hashes, and logs are in
[CHECKPOINT.md](CHECKPOINT.md). The profiler remains a focused archive gate.

The September 18 CMake baseline used fresh, forced-execution staging Buildbarn
measurements for the tracer aggregates. They took 5,030.412 seconds on native amd64 and 9,051.585 seconds on
native arm64. The corresponding local measurements were 754.489 seconds for
amd64 and 710.959 seconds for an arm64 cross build. Staging generally ran only
two to five product actions at once despite `--jobs=50`, and repeated LLVM
runtime construction dominated the remote runs. These are CPU build actions;
they do not use the local GPU. The exact cache policy, commands, invocation
IDs, launcher regression fix, and checksummed logs are in
[the matrix review](evidence/full-tracer-matrix-2026-09-18/review.json).

The native Bazel runtime implementation, final 202-product verification, and
forced/cache/no-op/source-edit measurements are recorded in the
[native runtime report](evidence/native-runtimes-2026-09-21/README.md).
The measured remote default is 25 jobs; normal builds accept cache hits.
The September 18 timings used different workspace inputs and are not a
like-for-like speedup comparison.

Install the pinned BuildBuddy CLI with:

```sh
bash tools/bazel/bootstrap-bb.sh
export PATH="$PWD/build/bin:$PATH"
```

## Incremental C development builds

Use `local-cached-amd64` on an amd64 Linux host or `local-cached-arm64` on
an arm64 Linux host. These configurations read the shared Buildbarn cache and
execute misses locally, avoiding the remote worker queue after a source edit:

```sh
bb build //bazel/products/tracer:ddtrace_fat_amd64_glibc_php85 \
  --config=local-cached-amd64
```

The architecture suffix selects the **execution host**, not the product being
built. For example, an amd64 host can cross-build `ddtrace_fat_arm64_all` with
`local-cached-amd64`. A native runtime smoke test still requires a matching host.
Use the same target/platform flags and retain the same Bazel output base between
edits. The environment, toolchains and execution-platform properties match the
corresponding `remote-hermetic-*` configuration so cached actions can be reused.
Top-level outputs are downloaded for local use; cached intermediate outputs are
downloaded when needed by a local action. Local results remain in the output base;
these development configurations do not upload them to the shared cache.

The default local strategy requires Linux sandbox namespace support. In a
container without that support, explicitly append `--config=local-processwrapper`:

```sh
bb build //bazel/products/tracer:ddtrace_fat_amd64_glibc_php85 \
  --config=local-cached-amd64 --config=local-processwrapper
```

Processwrapper isolates action directories but does not isolate host filesystem
or network access, so it is a development fallback, not a hermetic acceptance
gate. Cache uploads remain disabled. Repository downloads can still be disabled
after prefetch with `--repository_disable_download`; remote cache access remains
enabled. The shared cache requires access to the existing staging endpoint.

### Checkout-local defaults

The shared `.bazelrc` optionally loads the Git-ignored `.bazelrc.local` from
the repository root. To make incremental builds the default on an amd64 Linux
host, put this in that file (use `local-cached-arm64` on an arm64 host):

```text
build --config=local-cached-amd64
# Only in containers without Linux sandbox namespace support:
# build --config=local-processwrapper
```

Then build the full amd64 tracer matrix, edit a C source file, and repeat the
same command without cleaning:

```sh
bb build //bazel/products/tracer:ddtrace_fat_amd64_all
```

Machine-specific `startup --output_base=/absolute/path` and
`build --repository_cache=/absolute/path` settings also belong in this local
file. Keep the output base stable across edits. Repository download disabling
is optional and should only be enabled after prefetching the required inputs;
override it with `--norepository_disable_download` when fetching new inputs.
Command-line configurations still take precedence, for example
`--config=remote-hermetic-amd64` to execute on remote amd64 workers or
`--config=local-hermetic` for strict local execution without the remote cache.
Removing the local file restores the shared defaults. It is not checked in,
so other checkouts and CI are unaffected.

Local strict execution requires a host on which Bazel's `linux-sandbox` can
create its mount and network namespaces. After repository prefetch, build
without repository network access with:

```sh
bb fetch //:php_all --config=local-hermetic
bb build //:php_all --config=local-hermetic --repository_disable_download
```

Fetch each focused product target under its selected platform configuration
before its offline build as well. The checkpoint records the exact commands
and configurations for each retained gate.

The optional staging Buildbarn configurations use the shared `ci/shared`
instance and the declared `x86-64` or `arm-a64` execution platform:

```sh
bb build //bazel/stages:remote_arch_amd64 --config=remote-arch-amd64 \
  --repository_disable_download --remote_accept_cached=false
bb build //bazel/stages:remote_arch_arm64 --config=remote-arch-arm64 \
  --repository_disable_download --remote_accept_cached=false
```

Run deterministic-output checks with the pinned CLI first in `PATH` so nested
Bazel invocations cannot fall back to an unrelated host wrapper:

```sh
BB_DISABLE_SIDECAR=1 bb detect nondeterminism \
  --bes_backend= \
  --bes_results_url= \
  --bazel_command='build //:php_all --config=local-hermetic'
```

The empty outer BES flags are required because the pinned detector otherwise
appends its public BuildBuddy endpoint after the nested Bazel command.

## Accepted native dependency and package gates

GNU libunwind is built from the Cargo-locked libdd-libunwind-sys 1.0.3
vendored GNU 1.8.3 source. Its four architecture/libc archive sets passed
ptrace and stepped-frame native smoke checks on matching remote workers. The
AppSec libxml2 adapter builds the vendored source as a PIC static archive from
the configured header SDK's NTS/ZTS provider, exports `AppsecLibxml2Info` and
`CcInfo`, and carries `-lpthread` for ZTS consumers. Its eight
architecture/libc/ZTS variants passed allocator, push-parser/tree,
version-script isolation, and native gates.

The target curl SDK is separate from the execution-tool runtime. Glibc uses
the CI-compatible curl 7.61.1/OpenSSL 1.1.1 closure imported from locked
CentOS image layers; musl uses curl 8.14.1 and its checksum-locked Alpine 3.22
package closure. Both providers validate architecture, libc, target triple,
SONAME, symbol-version floor, forbidden dynamic tags, and every transitive
`DT_NEEDED` edge before exposing `CcInfo`.

`//bazel/products/tracer:tracer_c_all` compiles the version-selected tracer C
sources for all 226 normalized SDK rows. The normal-product layer links 202 of
those rows into complete split `ddtrace.so` products and validates their ELF
machine, libc, debug-link, and exported-symbol contracts. Each public archive
label carries `TracerCInfo`, preserving its PHP ABI and curl runtime/data
closure for the final shared-library link.

`//bazel/products/loader:loader_stage_all` creates four narrow loader package
archives, one per architecture/libc pair. Each contains the real loader DSO,
split debug file, generated INI, SDK metadata, and version under the matching
`linux-gnu/loader` or `linux-musl/loader` path. The archives depend on their
loader ELF-check markers. They are package stages, not complete SSI archives.
`ssi_payload` and `deterministic_ssi_bundle` normalize directories to 0755,
ordinary files to 0644, declared executables to 0755, and timestamps to the
epoch. A complete SSI target still needs projection of the tracer products,
profiler DSOs, AppSec, common files, licenses, and API-specific layout entries.

The typed `ProductArtifactInfo` boundary is available to packages:
`product_matrix_artifact` derives a product, role, and scope from the canonical
PHP product matrix, while `ssi_payload` consumes its validation markers as
declared action inputs. The existing loader staging package continues to map
direct filegroups with its explicit ELF-validation dependencies. Input paths
are commonly symlinks in Bazel's execroot, so the payload assembler
dereferences that single input, rejects links below the copied source, and
verifies that no link is published in the payload.

Remote success is evidence for the configured target and matching execution
worker. It does not replace local-hermetic acceptance on a host able to create
the required Linux sandbox mounts.
