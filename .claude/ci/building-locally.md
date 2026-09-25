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

### PHP selection differs by build type

Bookworm test images use bare `switch-php` variants: `nts`, `debug`, `zts`,
`nts-asan`, and `debug-zts-asan`. The portable release builder instead uses
`PHP_SDK_VERSION` to select a version and thread-safety mode. Portable scripts
do not call `switch-php`.

### make vs make static

`make` links Rust inline and produces a self-contained `ddtrace.so`.
`make static` splits the Rust library out into `.a` archives (used by
the package pipeline's two-phase build). For local testing, always use
`make` unless you specifically need the split build.

### Linting the libdatadog submodule (fmt + clippy)

To validate Rust changes in the `libdatadog/` submodule, run from inside it:

```bash
cd libdatadog
cargo +nightly fmt --all --quiet && \
  cargo clippy --workspace --all-targets --all-features -- -D warnings
```

A rustc ≥1.87 toolchain override is active in the repo, so plain `cargo clippy`
picks up the correct toolchain — do NOT force `+stable` (the default stable is
older) or a pinned `+1.87.0`, and do not lint per-crate with `-p`. Only `fmt`
needs `+nightly`.

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

### For system tests (portable release build)

System tests normally consume a package assembled from the CI portable
artifacts. To reproduce the portable tracer build locally, use the sidecar,
tracer, and link sequence in
[compile-artifacts.md](compile-artifacts.md#local-reproduction).

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

### For release / system tests (portable)

Use the portable image pinned in `.gitlab/portable-builds.yml`:

```bash
.claude/ci/dockerh --cache portable-appsec-8.3 --overlayfs --root \
    "$PORTABLE_IMAGE" \
    -e PHP_VERSION=8.3 \
    -e ABI_NO=20230831 \
    -- bash -c '.gitlab/build-appsec.sh'
```

Set `PORTABLE_IMAGE` from `.portable_build.image` as shown in
[compile-artifacts.md](compile-artifacts.md#local-reproduction).

### For native tests (bookworm, with test targets)

Uses cmake directly with test flags. This is a **different build**
from the CI release build above (builds test targets, uses libc++):

```bash
mkdir -p appsec/build && cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=Debug \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++" \
  -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
  -DDD_APPSEC_TESTING=ON
make -j$(nproc) xtest
```

For ASAN, add `-DENABLE_ASAN=ON` to cmake. See
[appsec-native-tests.md](appsec-native-tests.md) for full details.

## Embedded AppSec Helper

The Rust AppSec helper is a workspace crate embedded in the tracer's sidecar
component. Run its checks through the integration Gradle project:

```bash
git submodule update --init --recursive \
  appsec/third_party/libddwaf-rust

cd appsec/tests/integration
./gradlew testHelperRust --info
./gradlew buildPortableLibdatadogPhp --info
```

The release package needs the AppSec extensions in
`appsec_$(uname -m)/` and `appsec/recommended.json`; there is no standalone
helper artifact.

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

### For release / packaging / system tests (portable)

`build-profiler.sh` takes two arguments: the output directory prefix
and the thread safety mode (`nts` or `zts`). It selects a PHP SDK with
`PHP_SDK_VERSION`. The output prefix must
match the directory layout expected by `generate-final-artifact.sh`:
`datadog-profiling/{architecture}/lib/php/{PHP_API}/`.

For a single version (e.g. 8.2, ABI `20220829`):

```bash
.claude/ci/dockerh --cache portable-profiler-8.2 --overlayfs \
    --root \
    "$PORTABLE_IMAGE" \
    -e CI_PROJECT_DIR=/project/dd-trace-php \
    -e PHP_VERSION=8.2 \
    -- bash -c 'PHP_VERSION=8.2 bash .gitlab/build-profiler.sh \
      datadog-profiling/$(uname -m)/lib/php/20220829 nts'
```

## Sidecar (Rust)

```bash
.claude/ci/dockerh --cache portable-sidecar --overlayfs --root \
    "$PORTABLE_IMAGE" \
    -e CI_PROJECT_DIR=/project/dd-trace-php \
    -e CARGO_HOME=/project/dd-trace-php/.cache/cargo \
    -- bash -c '.gitlab/build-sidecar.sh'
```

## SSI Loader

```bash
.claude/ci/dockerh --cache portable-loader --overlayfs --root \
    "$PORTABLE_IMAGE" \
    -- bash -c '.gitlab/build-loader.sh'
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
- `appsec_$(uname -m)/` — appsec extensions (`ddappsec-{API}[-zts].so`)
- `appsec/recommended.json` — bundled AppSec rules
- `datadog-profiling/{architecture}/lib/php/{API}/` — profiler
  extensions

Missing files cause hard `cp` failures. This means that we need to build (or
download from CI) all these individual artifacts. This is rarely desirable when
testing locally. See the section "Slim package with debug binaries" for a more
practical alternative when locally producing artifacts from some jobs, like
system tests.

**Release naming is shared by platform.** Both GNU/glibc and Alpine/musl use
`ddtrace-{API}.so` and `ddtrace-{API}-zts.so`. Debug and debug-ZTS extensions
exist only for glibc packages. AppSec release extensions likewise have no
platform suffix.

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

The script reads from `standalone_$(uname -m)/` (portable standalone `.so`
files from the parent tracer builds), not `extensions_$(uname -m)/` (the
linked extension artifact set).

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
uses the portable PHP SDK image and assembles the tarball directly in
the `dd-library-php/` layout that
`datadog-setup.php` expects.

**Note:** this produces debug (unoptimized) binaries, which differ from the
release binaries built by CI. They are suitable for development and
troubleshooting but not for performance testing.

```bash
# Tracer only (gnu, x86_64, PHP 8.2, NTS)
tooling/bin/build-debug-artifact gnu-x86_64-8.2-nts

# Tracer + appsec (extension + embedded helper) + profiler
tooling/bin/build-debug-artifact gnu-x86_64-8.2-nts --appsec --profiler

# Musl/arm64 variant, custom output directory (preferred if the location is somewhere else)
tooling/bin/build-debug-artifact musl-aarch64-8.2-nts /tmp/out
```

All products build in parallel. Build logs go to a temporary
directory printed at the start.
