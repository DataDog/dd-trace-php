# Hermetic Linux Bazel build

This directory contains the additive Bazel build for Linux amd64 and arm64,
with glibc 2.17 and dynamic musl targets. Build actions use checksum-locked
execution tools, explicit target sysroots, and LLVM 20.1.4 target runtimes.
Repository setup is the only phase that downloads inputs.

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

## Retained focused product gates

The retained PHP 8.5 amd64 glibc fat tracer target and its load/unload plus
sidecar smoke gate are:

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
These are focused retained gates. They do not make a full tracer or profiler
matrix available; exact commands, exits, source and artifact hashes are in
[CHECKPOINT.md](CHECKPOINT.md).

Install the pinned BuildBuddy CLI with:

```sh
bash tools/bazel/bootstrap-bb.sh
export PATH="$PWD/build/bin:$PATH"
```

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

`//bazel/products/tracer:tracer_c_all` currently compiles and validates the 97
PHP 8.5 tracer C translation units for normal NTS amd64/arm64 and glibc/musl
targets with explicit production optimization and debug information. Each
public archive label carries `TracerCInfo`, preserving its PHP ABI and curl
runtime/data closure for the final shared-library link. These are reviewed C
inputs; they do not yet claim a complete `ddtrace.so` product.

`//bazel/products/loader:loader_stage_all` creates four narrow loader package
archives, one per architecture/libc pair. Each contains the real loader DSO,
split debug file, generated INI, SDK metadata, and version under the matching
`linux-gnu/loader` or `linux-musl/loader` path. The archives depend on their
loader ELF-check markers. They are package stages, not complete SSI archives.
`ssi_payload` and `deterministic_ssi_bundle` normalize directories to 0755,
ordinary files to 0644, declared executables to 0755, and timestamps to the
epoch. A complete SSI target still needs tracer, profiler, AppSec, common
files, licenses, and API-specific layout entries.

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
