# Building Artifacts Locally

Consolidated reference for building project artifacts locally. For
CI job details and exact CI command equivalents, see
[compile-artifacts.md](compile-artifacts.md).

## Common Gotchas

### CARGO_HOME is root-owned in CI images

`/rust/cargo/` in CI images is root-owned. When running `dockerh` as non-root
(i.e. without `--root`), Cargo cannot write to it. Override it:

- With `--overlayfs` (recommended): pass
  `-e CARGO_HOME=/project/dd-trace-php/.cache/cargo`.
- Without overlayfs, place it in one of the cache bind mounted directories:
  `-e CARGO_HOME=/project/dd-trace-php/tmp/cargo_home`.

This affects `build-sidecar.sh`, profiler builds, and any other Rust build that
does not use `--root`. First local run downloads all crates from scratch.

### Submodule initialisation

Before any build, ensure the relevant submodules are initialised
(see also [../general.md](../general.md) section 4):

```bash
# Tracer extension (ddtrace.so) — needs libdatadog
git submodule update --init libdatadog

# Appsec extension/helper — needs libddwaf-rust
git submodule update --init --recursive \
  appsec/third_party/libddwaf-rust
```

### switch-php naming differs between images

On **centos-7** images, PHP variants are version-prefixed: `8.3`,
`8.3-debug`, `8.3-zts`. On **bookworm** images, variants are bare
names: `nts`, `debug`, `zts`, `nts-asan`, `debug-zts-asan`.

Build scripts that call `switch-php` internally (e.g.
`compile_extension.sh`, `build-tracing.sh`, `build-appsec.sh`,
`build-profiler.sh`) handle this themselves and need `--root` (not
`--php`) so they can modify `/usr/local/bin/` symlinks.

### devtoolset-7 on centos-7

The centos-7 base ships GCC 4.8, which is too old for C++17 code
(appsec extension). On centos-7 images, activate GCC 7 first:

```bash
source /opt/rh/devtoolset-7/enable
```

This is needed for `build-appsec.sh` on centos-7 but not on bookworm
(which has a modern GCC).

### make vs make static

`make` links Rust inline and produces a self-contained `ddtrace.so`.
`make static` splits the Rust library out into `.a` archives (used by
the package pipeline's two-phase build). For local testing, always use
`make` unless you specifically need the split build.

## Tracer Extension (ddtrace.so)

### For test jobs (bookworm, debug build)

Used before running tracer unit tests, .phpt tests, etc.:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
git submodule update --init libdatadog
make -j$(nproc) all
make install
'
```

`make install` (not `make install_all`) suffices for `test_c` and
`test_opcache`. PHPUnit jobs need these additional steps after
`make all`:

```bash
make install_all
composer update --no-interaction
make generate
```

See [tracer-unit-tests.md](tracer-unit-tests.md#phpunit-unit-tests)
for full PHPUnit run commands.

### For system tests (centos-7, release-like build)

Used when building packages for system-tests. Targets GLIBC 2.17 for
maximum compatibility:

```bash
.claude/ci/dockerh --cache systest-82 --php 8.2 \
  datadog/dd-trace-ci:php-8.2_centos-7 -- \
  bash -c 'export CARGO_HOME=$PWD/tmp/cargo_home; make -j$(nproc)'
```

First build: ~20 min (Rust sidecar). Incremental (C-only): ~1 min.

For `-O0` debugging (fewer `<optimized out>` in gdb):

```bash
CFLAGS="-std=gnu11 -O0 -g" make -j$(nproc)
```

### Via CI compile script (exact CI reproduction)

Reproduces the `compile extension: debug` CI job exactly:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_bookworm-6 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e SHARED=1 \
    -- bash .gitlab/compile_extension.sh
```

See [compile-artifacts.md](compile-artifacts.md) for all CI compile
job variants (ASAN, ZTS, package pipeline, etc.).

### ASAN build

Use a **separate cache** from the normal debug build. `COMPILE_ASAN=1`
enables `-fsanitize=address` in the Rust sidecar.

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
export COMPILE_ASAN=1
make -j$(nproc) all
'
```

## Appsec Extension

### For release / system tests (centos-7)

Needs `devtoolset-7` for C++17 support:

```bash
.claude/ci/dockerh --cache compile-appsec-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.3 bash .gitlab/build-appsec.sh'
```

(`build-appsec.sh` sources `devtoolset-7` internally on centos-7.)

### For native tests (bookworm, with test targets)

Uses cmake directly with test flags. This is a **different build**
from the CI release build above (builds test targets, uses libc++):

```bash
mkdir -p appsec/build && cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_HELPER=OFF \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++" \
  -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
  -DDD_APPSEC_TESTING=ON
make -j$(nproc) xtest
```

For ASAN, add `-DENABLE_ASAN=ON` to cmake. See
[appsec-native-tests.md](appsec-native-tests.md) for full details.

## Appsec Helper

The tarball needs the Rust helper binary in `appsec_$(uname -m)/` as
`libddappsec-helper.so`, plus `appsec/recommended.json`.

### Rust helper

Image is on Docker Hub. Output: `appsec_$(uname -m)/libddappsec-helper.so`.

```bash
git submodule update --init --recursive \
  appsec/third_party/libddwaf-rust

.claude/ci/dockerh --cache compile-appsec-helper-rust --overlayfs \
    datadog/dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash .gitlab/build-appsec-helper-rust.sh
```

## Profiler Extension

### For correctness tests (bookworm)

`CARGO_TARGET_DIR` **must** be set explicitly (see
[github-actions-profiler.md](github-actions-profiler.md) for why):

```bash
dockerh --cache profiler-8.3-nts --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/target
cd profiling && cargo rustc --features=trigger_time_sample \
  --profile profiler-release --crate-type=cdylib
'
```

### For release / packaging / system tests (centos-7)

Bookworm is too recent for binary compatibility purposes.

`build-profiler.sh` takes two arguments: the output directory prefix
and the thread safety mode (`nts` or `zts`). It calls `switch-php`
internally, so use `--root` (not `--php`). The output prefix must
match the directory layout expected by `generate-final-artifact.sh`:
`datadog-profiling/{triplet}/lib/php/{PHP_API}/`.

Build one PHP version at a time (each centos-7 image ships one
version). For a single version (e.g. 8.2, ABI `20220829`):

```bash
.claude/ci/dockerh --cache compile-profiler-8.2-gnu --overlayfs \
    --root \
    datadog/dd-trace-ci:php-8.2_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.2 bash .gitlab/build-profiler.sh \
      datadog-profiling/x86_64-unknown-linux-gnu/lib/php/20220829 nts'
```

## Sidecar (Rust)

```bash
.claude/ci/dockerh --cache compile-sidecar-gnu --overlayfs \
    datadog/dd-trace-ci:php-8.1_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e CARGO_HOME=/project/dd-trace-php/.cache/cargo \
    -- bash -c 'HOST_OS=linux-gnu bash .gitlab/build-sidecar.sh'
```

## SSI Loader

```bash
# linux-gnu
.claude/ci/dockerh --cache compile-loader-gnu --overlayfs \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'HOST_OS=linux-gnu bash .gitlab/build-loader.sh'

# linux-musl (requires --root for apk add)
.claude/ci/dockerh --cache compile-loader-musl --overlayfs --root \
    datadog/dd-trace-ci:php-compile-extension-alpine-8.3 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'HOST_OS=linux-musl bash .gitlab/build-loader.sh'
```

## Release Package Assembly

`generate-final-artifact.sh` assembles a release tarball from
compiled artifacts. It takes three arguments:

```
generate-final-artifact.sh VERSION OUTPUT_DIR PROJECT_ROOT
```

- `VERSION` — version string (from the `VERSION` file)
- `OUTPUT_DIR` — where to write the tarball (e.g. `build/packages`)
- `PROJECT_ROOT` — repo root (for PHP stub files in `src/`, `ext/`)

Set `TRIPLET` to limit assembly to one platform (e.g.
`x86_64-unknown-linux-gnu`). Without it, the script tries all
platforms and fails if artifacts are missing.

**Prerequisites:** the script expects these directories to contain
compiled `.so` files:
- `extensions_$(uname -m)/` — ddtrace extensions
  (`ddtrace-{API}[-zts|-debug|-debug-zts].so`)
- `appsec_$(uname -m)/` — appsec extensions (`ddappsec-{API}[-zts].so`) +
  Rust helper (`libddappsec-helper.so`) + `recommended.json`
- `datadog-profiling/{triplet}/lib/php/{API}/` — profiler
  extensions

Missing files cause hard `cp` failures. This means that we need to build (or
download from CI) all these individual artifacts. This is rarely desirable when
testing locally. See the section "Slim package with debug binaries" for a more
practical alternative when locally producing artifacts from some jobs, like
system tests.

**Naming conventions differ by platform.** GNU/glibc extensions use
bare names (`ddtrace-{API}.so`, `ddtrace-{API}-zts.so`) plus
`-debug` and `-debug-zts` variants (4 total). Alpine/musl extensions
use the `-alpine` suffix (`ddtrace-{API}-alpine.so`,
`ddtrace-{API}-alpine-zts.so`) and have **no `-debug` variants**
(2 total). Appsec follows the same pattern (no `-debug` for either
platform).

The script only needs basic shell tools (`cp`, `tar`, `mkdir`).
The `php_fpm_packaging` image is used in CI because the same job
also runs nfpm for .deb/.rpm/.apk, but any image with bash works
for tarball assembly alone.

### Build datadog-setup.php

```bash
docker run --rm -v "$(pwd)":/work -w /work php:8.2-cli \
  bash -c 'make build/packages/datadog-setup.php VERSION=$(cat VERSION)'
```

### Assemble the tarball (glibc amd64)

The `php_fpm_packaging` image has entrypoint `["bash"]`, so pass
`-c '...'` directly (not `bash -c '....'`).

```bash
.claude/ci/dockerh --cache pkg-amd64-gnu --overlayfs \
  datadog/dd-trace-ci:php_fpm_packaging -- -c '
set -e
TRIPLET=x86_64-unknown-linux-gnu \
  ./tooling/bin/generate-final-artifact.sh \
  $(<VERSION) "build/packages" "${PWD}"
'
.claude/ci/docker-upper-cp dd-ci-pkg-amd64-gnu \
  build/packages build/packages
```

Output in `build/packages/`:
- `dd-library-php-<version>-x86_64-linux-gnu.tar.gz`
- `datadog-setup.php`

To also build `.deb`/`.rpm` packages (full CI equivalent), add the
fpm targets before the tarball assembly in the same dockerh session:

```bash
.claude/ci/dockerh --cache pkg-amd64-gnu --overlayfs \
  datadog/dd-trace-ci:php_fpm_packaging -- -c '
set -e
make -j 4 .rpm.x86_64 .deb.x86_64 .tar.gz.x86_64
TRIPLET=x86_64-unknown-linux-gnu \
  ./tooling/bin/generate-final-artifact.sh \
  $(<VERSION) "build/packages" "${PWD}"
'
.claude/ci/docker-upper-cp dd-ci-pkg-amd64-gnu \
  build/packages build/packages
```

For Alpine/musl, the target is `.apk.x86_64` (or `.apk.aarch64`).

### Assemble the tarball (arm64)

The `php_fpm_packaging` image has no arm64 variant. Use
`ubuntu:24.04` for tarball assembly only. In CI, all packaging runs
on amd64 runners — arm64 `.deb`/`.rpm`/`.apk` packages are
cross-built on amd64 (architecture is just a metadata field in fpm).

```bash
.claude/ci/dockerh --cache pkg-arm64-gnu --overlayfs \
  ubuntu:24.04 \
  -e TRIPLET=aarch64-unknown-linux-gnu \
  -- bash -c '
set -e
./tooling/bin/generate-final-artifact.sh \
  $(<VERSION) "build/packages" "${PWD}"
'
.claude/ci/docker-upper-cp dd-ci-pkg-arm64-gnu \
  build/packages build/packages
```

### SSI Loader Package Assembly

`generate-ssi-package.sh` assembles the SSI (loader) tarball. Unlike
`generate-final-artifact.sh`, it runs `objcopy --only-keep-debug` and
`strip` on every `.so` — **empty stub files will fail**. You need real
compiled artifacts.

The script reads from `standalone_$(uname -m)/` (standalone `.so`
files from `compile tracing extension`), not `extensions_$(uname -m)/`
(which has `.a` archives for the link phase).

For aarch64, the script uses cross-tools (`aarch64-linux-gnu-objcopy`,
`aarch64-linux-gnu-strip`). These are available in the
`php_fpm_packaging` image but not on a native arm64 host. **All
`package loader` CI jobs run on amd64 runners**, even for arm64
packages.

```bash
.claude/ci/dockerh --cache pkg-loader --overlayfs \
  datadog/dd-trace-ci:php_fpm_packaging \
  -e ARCHITECTURE=x86_64 \
  -- -c '
set -e
./tooling/bin/generate-ssi-package.sh $(<VERSION) build/packages
'
.claude/ci/docker-upper-cp dd-ci-pkg-loader \
  build/packages build/packages
```

Set `ARCHITECTURE=aarch64` for arm64 (still runs in the amd64
`php_fpm_packaging` image, using cross-tools).

### Slim package with debug binaries (preferred, if possible)

`tooling/bin/build-debug-artifact` builds a tarball containing only the PHP
version you need — no stubs, no `generate-final-artifact.sh`. It
uses the same centos-7 images as the package pipeline and assembles
the tarball directly in the `dd-library-php/` layout that
`datadog-setup.php` expects.

**Note:** this produces debug (unoptimized) binaries, which differ from the
release binaries built by CI. They are suitable for development and
troubleshooting but not for performance testing.

```bash
# Tracer only (gnu, x86_64, PHP 8.2, NTS)
tooling/bin/build-debug-artifact gnu-x86_64-8.2-nts

# Tracer + appsec (extension + both helpers) + profiler
tooling/bin/build-debug-artifact gnu-x86_64-8.2-nts --appsec --profiler

# Musl/arm64 variant, custom output directory (preferred if the location is somewhere else)
tooling/bin/build-debug-artifact musl-aarch64-8.2-nts /tmp/out
```

All products build in parallel. Build logs go to a temporary
directory printed at the start.
