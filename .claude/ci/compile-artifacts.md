# Compile / Build Artifact Jobs

CI builds release Linux artifacts once per architecture in the parent
pipeline. Both glibc and musl packaging and test jobs consume those portable
artifacts. Jobs only build their own extension when they require a materially
different binary, such as debug, ASAN, coverage, or profiler test features.

## Source Files

- `.gitlab/portable-builds.yml` defines the parent-pipeline portable build DAG.
- `.gitlab/ci-targets.php` owns the architecture, PHP version, and ABI matrix.
- `.gitlab/check-portable-builds.php` verifies that the static YAML matches the
  matrix used by the child-pipeline generators.
- `.gitlab/build-sidecar.sh` builds the portable Rust sidecar and mock generator.
- `.gitlab/build-tracing.sh` builds NTS and ZTS tracer archives and standalone
  extensions.
- `.gitlab/link-tracing-extension.sh` links each job's tracer archives to the
  portable sidecar.
- `.gitlab/build-appsec.sh`, `.gitlab/build-profiler.sh`, and
  `.gitlab/build-loader.sh` build their portable extensions.
- `.gitlab/generate-package.php` defines the package child pipeline and its
  debug, ASAN, Windows, and PECL builds.

## Portable Parent Builds

The `php-buildonly-rust` image provides musl, `musl-clang`, and PHP SDKs for all
supported PHP versions. Portable jobs use the musl toolchain but deliberately
produce unsuffixed release artifacts. A release artifact is shared by glibc and
musl consumers; there is no separate Alpine build.

| Job | Cardinality | Output |
|---|---:|---|
| `prepare portable code` | once | stamped `VERSION` and generated bridge PHP |
| `cache portable cargo deps` | per arch | Cargo dependency cache |
| `compile portable tracing sidecar` | per arch | `libdatadog_php_{arch}.{a,so}` and `php_sidecar_mockgen_{arch}` |
| `compile portable tracing extension` | per PHP ABI and arch | final and standalone NTS/ZTS tracer `.so` files |
| `compile portable appsec extension` | per PHP ABI and arch | NTS/ZTS `ddappsec.so` |
| `compile portable profiler extension` | per PHP ABI and arch | NTS/ZTS profiler `.so` |
| `compile portable loader` | per arch | SSI loader `.so` |
| `collect portable tracing artifacts` | PHP 7/8 group and arch | relayed tracer artifacts for child pipelines |
| `collect portable component artifacts` | per arch | relayed AppSec and profiler artifacts |
| `collect portable runtime artifacts` | per arch | relayed sidecar and loader shared objects |
| `portable builds complete` | per arch | fan-in gate used by the package trigger |

The sidecar is built with the `tracer-release` profile for the native musl
target. It dynamically uses musl but statically links the unwind implementation
so the result can run in either a glibc or musl process. The C and C++
extensions use `PHP_SDK_VERSION` rather than a distribution-specific PHP
installation.

Each build script rejects a result containing versioned `GLIBC_*` symbols. The
tracer link job repeats that check on every final extension.

## Dependency Graph

```text
generate-templates
  |
  +-- prepare portable code
  |     +-- tracer compile/link --+
  |     +-- AppSec extensions     +-- artifact collectors
  |     +-- loader              --+          |
  +-- Cargo cache                           +-- portable build gate
        +-- sidecar ------------+-- tracer  |          |
        +-- profiler -----------------------+          +--> package child

parent portable artifacts
  +-- package child: glibc and musl packages, SSI package
  +-- profiler child: ordinary PHP language tests
  +-- AppSec child: release, ZTS, musl, and SSI integration tests
```

Child jobs use `needs:pipeline:job` to download artifacts from the parent
pipeline. Cross-pipeline needs download artifacts but do not schedule the
producer, so each trigger waits for the relevant parent jobs before creating
its child pipeline.

## Builds That Intentionally Remain Separate

- Tracer unit, PHPT, and integration jobs use debug or ASAN extensions.
- Package ASAN jobs build the instrumented debug-ZTS tracer.
- Profiler feature tests compile with test-only feature flags such as
  `debug_stats`, `stack_walking_tests`, and tracing subscribers.
- Microbenchmarks rebuild candidate and baseline binaries with
  `-falign-functions=64` to stabilize comparisons.
- AppSec native and coverage jobs enable test targets, ASAN, or coverage
  instrumentation.
- Shared C-component jobs build Debug, ASAN, or UBSAN binaries.
- Windows jobs build PE DLLs with the Windows SDK.
- The PECL job validates source-package construction rather than consuming an
  installed release extension.

An ordinary release consumer should not be added to this list. If it can load
the portable artifact, make it depend on that artifact even if it historically
built its own copy.

## Package Child Builds

The package child pipeline still builds the following distinct variants:

- `compile tracing extension debug` with the portable toolchain for debug-ABI
  package contents;
- `compile tracing extension asan` for the ASAN package;
- `compile extension windows` for Windows DLLs and symbols; and
- `pecl build` for the source package.

All release Linux package jobs fetch the parent tracer, AppSec, profiler, and
loader artifacts. `generate-final-artifact.sh` puts the same NTS/ZTS release
extensions into glibc and musl packages. Debug extensions are included only in
glibc packages. `generate-ssi-package.sh` likewise uses the same release
artifacts for both runtime families.

The platform names remain packaging identifiers:

| Triplet | Arch | Package targets |
|---|---|---|
| `x86_64-alpine-linux-musl` | amd64 | `.apk.x86_64` |
| `aarch64-alpine-linux-musl` | arm64 | `.apk.aarch64` |
| `x86_64-unknown-linux-gnu` | amd64 | `.rpm.x86_64`, `.deb.x86_64`, `.tar.gz.x86_64` |
| `aarch64-unknown-linux-gnu` | arm64 | `.rpm.arm64`, `.deb.arm64`, `.tar.gz.aarch64` |
| `x86_64-pc-windows-msvc` | amd64 | `dbgsym.tar.gz` |

## Local Reproduction

Initialize the Rust submodules first:

```bash
git submodule update --init libdatadog
git submodule update --init --recursive \
  appsec/third_party/libddwaf-rust
```

The portable image is pinned in `.gitlab/portable-builds.yml`. This example
builds and links one architecture and one tracer ABI. Use a dedicated cache
because the output is written into the overlay:

```bash
IMAGE=$(ruby -e '
  require "yaml"
  puts YAML.load_file(".gitlab/portable-builds.yml")[".portable_build"]["image"]
')

.claude/ci/dockerh --cache portable-8.3 --overlayfs --root "$IMAGE" \
  -e CI_PROJECT_DIR=/project/dd-trace-php \
  -e CARGO_HOME=/project/dd-trace-php/.cache/cargo \
  -e PHP_VERSION=8.3 \
  -e ABI_NO=20230831 \
  -- bash -c '
set -e
.gitlab/build-sidecar.sh
.gitlab/build-tracing.sh
'
```

Use `bash -c`, not `bash -lc`, with this image. A login shell can reset `PATH`
and hide the Rust toolchain under `/root/.cargo/bin` when `dockerh` supplies its
temporary `HOME`.

To build the other portable components in the same overlay:

```bash
.gitlab/build-appsec.sh
.gitlab/build-profiler.sh \
  datadog-profiling/$(uname -m)/lib/php/20230831 nts
.gitlab/build-profiler.sh \
  datadog-profiling/$(uname -m)/lib/php/20230831 zts
.gitlab/build-loader.sh
```

Most scripts compress debug sections with `objcopy`. Debug information is
retained. Check portability directly with:

```bash
readelf --version-info path/to/extension.so | grep GLIBC_
```

No output is expected.

## Gotchas

- The static parent YAML must remain aligned with `ci-targets.php`. Run
  `php .gitlab/check-portable-builds.php` after changing either file.
- Each tracer build produces and consumes its own fat-link flags and
  retained-symbol list.
- NTS and ZTS artifacts share an ABI number but have different suffixes. Never
  load an NTS extension in a ZTS runtime or vice versa.
- `libdatadog` and `appsec/third_party/libddwaf-rust` must be initialized for
  sidecar builds.
- Cargo cache jobs push the cache; sidecar and profiler jobs pull it without
  writing it back.
- Artifact directories from several `needs` entries deliberately merge. Keep
  output filenames unique by PHP ABI and architecture.
- The Alpine compile images remain in use for jobs that need an Alpine runtime
  or custom test build. They are not release artifact producers.
- Windows compile jobs run Docker directly on Windows runners and manually
  clone the repository because they use `GIT_STRATEGY: none`.
