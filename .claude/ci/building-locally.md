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
# Root Rust workspace / tracer runtime / profiling and embedded AppSec helper
git submodule update --init --recursive \
  libdatadog appsec/third_party/libddwaf-rust
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

`make` links Rust inline and produces a self-contained tracer `ddtrace.so`;
use `make compile_combined` to include profiling in a development build.
`make static` is the legacy split build: its C archive and slim DSO still need
the Rust runtime at the final link or via SSI preload. Do not treat the slim
DSO as self-contained. Current release NTS/ZTS builds use the ThinLTO targets
described below, not the old all-version compile/link sequence.

### Linting the libdatadog submodule (fmt + clippy)

To validate Rust changes in the `libdatadog/` submodule, run from inside it:

```bash
cd libdatadog
cargo +nightly fmt --all --quiet && \
  cargo clippy --workspace --all-targets --all-features -- -D warnings
```

A rustc ≥1.91 toolchain override is active in the repo, so plain `cargo clippy`
picks up the correct toolchain. The `+nightly` toolchain is likely not in the
development images. You can install it temporarily in a running image, or run
it on the host.

## Tracer Extension (ddtrace.so)

### For test jobs (bookworm, debug build)

Used before running tracer unit tests, .phpt tests, etc.:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
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
    datadog/dd-trace-ci:php-8.3_bookworm-11 \
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
  datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
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

**Do not run `phpize`/`configure` directly in the repo root** -- it overwrites
the tracked top-level `Makefile` (and `Makefile.fragments`/`Makefile.objects`)
with a generated one, corrupting the checkout for every other target. Always
go through the root `Makefile`'s own targets below, which copy sources into an
isolated `tmp/build_{combined,profiler}/` directory (via the `all` target's
`$(BUILD_DIR)/configure` prerequisite) before running `phpize`/`configure`/`make`
there -- the checked-out root `Makefile`/`configure` are never touched.

### Combined tracer+profiler (preferred -- matches what CI ships)

Most local profiler work should build the **combined** `ddtrace.so` (tracer +
profiling in one extension), since that is the product shipped in release
packages. CI also builds and tests the standalone profiler. Use
`make compile_combined` (`make install_combined` also copies the DSO into the
PHP extension directory; `make install_all_combined` additionally installs
`ddtrace.ini`):

```bash
dockerh --cache profiler-8.3-nts --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
cd /project/dd-trace-php
make compile_combined -j"$(nproc)"
php -n -d extension=tmp/build_combined/modules/ddtrace.so --ri ddtrace
'
```

`compile_combined` sets `EXTRA_CONFIGURE_OPTIONS="--enable-ddtrace-tracer
--enable-ddtrace-profiling"` and `DDTRACE_PROFILING_FEATURES=trigger_time_sample`,
and builds in `tmp/build_combined/` (`BUILD_SUFFIX=combined`) so it doesn't
collide with a plain tracer-only `tmp/build_extension/` build in the same
checkout.

### Standalone profiler (only for testing the standalone artifact itself)

The standalone `datadog-profiling.so` (no tracer) remains supported and is
**built and tested, but not shipped**. CI uses it in sanitizer jobs, older
correctness cells, and product-loading comparisons. Use this target for
standalone-specific development and tests, not as a substitute for testing
the combined release product. Do not co-load it with `ddtrace.so`:

```bash
dockerh --cache profiler-8.3-nts-standalone --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
cd /project/dd-trace-php
make compile_profiler -j"$(nproc)"
php -n -d extension=tmp/build_profiler/modules/datadog-profiling.so --ri datadog-profiling
'
```

Output: `tmp/build_profiler/modules/datadog-profiling.so`. See
[github-actions-profiler.md](github-actions-profiler.md) for
`PROFILER_FEATURES` and the ASAN variant (`make compile_profiler_asan`).

### For release / packaging / system tests (centos-7)

Bookworm development builds are not release binaries. Linux release jobs use
matched Clang/LLD/Rust cross-language ThinLTO for NTS and ZTS on both centos-7
(glibc 2.17 baseline) and Alpine. The PHP debug ABI stays non-LTO.

For a single glibc compile cell (PHP 8.3, ABI `20230831`), build NTS and ZTS
with `make xlang-lto`, then copy them into the package input directory:

```bash
.claude/ci/dockerh --cache compile-combined-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -- bash -c '
set -e
arch=$(uname -m)
mkdir -p "extensions_$arch"
switch-php 8.3
make -s xlang-lto
cp "tmp/release-variants/$arch/php-8.3/nts/combined/ddtrace.so" \
   "extensions_$arch/ddtrace-20230831.so"
PHP_VERSION=8.3 .gitlab/build-profiler.sh "extensions_$arch" debug combined \
   ddtrace-20230831-debug.so
switch-php 8.3-zts
make -s xlang-lto
cp "tmp/release-variants/$arch/php-8.3/zts/combined/ddtrace.so" \
   "extensions_$arch/ddtrace-20230831-zts.so"
'
```

`build-profiler.sh OUT_DIR MODE PRODUCT FILENAME` is the regular non-LTO
phpize/configure build wrapper; release CI uses it for the debug ABI, not
NTS/ZTS. It switches PHP internally, so it needs `--root`, not `--php`.
For Alpine, source `$BASH_ENV` and use the `switch_php` function before each
`make xlang-lto`; destination filenames include `-alpine` before `-zts`.

For a local SSI pair, select the desired NTS/ZTS SDK and run
`make build-ssi-ddtrace` (which also runs `build-ssi-common`). Output is
`tmp/release-variants/<arch>/php-<version>/<nts|zts>/ssi/{ddtrace.so,libdatadog_php.so}`.
CI renames the extension to `ssi_<arch>/ddtrace-<API>[-alpine][-zts].so`
and supplies the common DSO once per platform from the sidecar job. The common
DSO is PHP-independent; the private PHP-ABI Rust archive is not shipped.
See [compile-artifacts.md](compile-artifacts.md) for the job graph and SSI inputs.

These examples build one PHP version, not all inputs needed by the full release
packager. For a single-version development package, use the slim debug builder
below; for full release assembly, build or download the complete ABI matrix.

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
compiled artifacts. Run it from the repository root with three arguments:

```
generate-final-artifact.sh VERSION OUTPUT_DIR PROJECT_ROOT
```

- `VERSION` — version string (from the `VERSION` file)
- `OUTPUT_DIR` — where to write the tarballs (e.g. `build/packages`)
- `PROJECT_ROOT` — repo root for merging PHP stub files in `src/` and `tracer/`

Set `TRIPLET` to limit assembly to one platform (e.g.
`x86_64-unknown-linux-gnu`). Without it, the script tries all
platforms and fails if artifacts are missing.

**Prerequisites:** for the selected artifact architecture (`x86_64` or
`aarch64`, not necessarily the packaging host's architecture):
- `extensions_<arch>/` — self-contained ddtrace extensions. PHP 7.1+ includes
  profiling; PHP 7.0 remains tracer-only. There is no separate profiler DSO input.
- `appsec_<arch>/` — AppSec extensions (`ddappsec-{API}[-alpine][-zts].so`).
  The helper is embedded in the Rust runtime, not a separate helper DSO input.
- `src/`, `appsec/recommended.json`, `profiling/LICENSE*`, and
  `profiling/NOTICE` — bundled PHP code, rules, and profiling licenses.

The script writes `.ddtrace[-zts|-debug].profiling` markers alongside Linux
PHP 7.1+ combined extensions so `datadog-setup.php` can detect profiling support.

Missing files cause hard `cp` failures. This means that we need to build (or
download from CI) all these individual artifacts. This is rarely desirable when
testing locally. See the section "Slim package with debug binaries" for a more
practical alternative when locally producing artifacts from some jobs, like
system tests.

**Naming conventions differ by platform.** GNU/glibc extensions use
bare names (`ddtrace-{API}.so`, `ddtrace-{API}-zts.so`) plus
`-debug` variants (3 total in ordinary release bundles). ASAN packaging uses
`-debug-zts` separately. Alpine/musl extensions
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

For each selected architecture, the script consumes:
- `ssi_<arch>/ddtrace-<API>[-alpine][-zts].so` for PHP 7.1+ combined SSI
  extensions, **not** the self-contained `extensions_<arch>/` binaries;
- `standalone_<arch>/ddtrace-20151012[-alpine][-zts].so` only for legacy
  PHP 7.0 tracer-only SSI (this directory does not mean standalone profiling);
- `libdatadog_php_<arch>[-alpine].so`, once per libc/platform, installed as
  `loader/libdatadog_php.so`;
- the GNU/musl loader DSOs, `appsec_<arch>/`, PHP `src/`, rules, licenses,
  and `loader/packaging/requirements.json`.

The loader preloads the PHP-independent common DSO. PHP 7.1+ SSI extensions
must not have `DT_NEEDED(libdatadog_php.so)` or an added runpath; private
PHP-ABI Rust archives are build inputs, not package contents.

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
mkdir -p build/packages
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

# Tracer + appsec (extension + embedded helper) + profiler
tooling/bin/build-debug-artifact gnu-x86_64-8.2-nts --appsec --profiler

# Musl/arm64 variant, custom output directory (preferred if the location is somewhere else)
tooling/bin/build-debug-artifact musl-aarch64-8.2-nts /tmp/out
```

The tracer (including profiling with `--profiler`) and AppSec builds run in
parallel; `--profiler` does not add a standalone profiler DSO. Build logs go
to a temporary directory printed at the start.
