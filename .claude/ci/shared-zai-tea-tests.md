# Shared Pipeline — ZAI, TEA, and C Components Tests

## CI Jobs

**Source:** `.gitlab/generate-shared.php` — generates the shared-trigger
child pipeline; all job definitions and matrices are inline.

| CI Job | Image | What it does |
|--------|-------|-------------|
| `Build & Test Tea` | `dd-trace-ci:php-{ver}_bookworm-6` | Builds the TEA (Test Execution Abstraction) library from `tea/`, runs its ctest suite, installs artifacts for downstream jobs |
| `Zend Abstract Interface Tests: [{ver}, {variant}]` | `dd-trace-ci:php-{ver}_bookworm-6` | Builds and tests the ZAI library (`zend_abstract_interface/`) against a specific PHP variant |
| `Extension Tea Tests: [{ver}, {variant}]` | `dd-trace-ci:php-{ver}_bookworm-6` | Builds ddtrace.so via `make install`, then builds and runs the extension-level TEA tests in `tests/tea/` |
| `ZAI Shared Tests: [{ver}]` | `dd-trace-ci:php-{ver}-shared-ext` | Runs ZAI tests with shared extensions (curl, json) on a special image; only PHP 7.4 and 8.0 |
| `C components ASAN` | `dd-trace-ci:centos-7`, `dd-trace-ci:php-compile-extension-alpine`, `dd-trace-ci:bookworm-6` | Builds C components (`components/`) with ASAN (on Debian) or plain Debug (on CentOS/Alpine), runs ctest |
| `C components UBSAN` | `dd-trace-ci:bookworm-6` | Builds C components with UBSAN, runs ctest with `--repeat until-fail:10` |
| `Configuration Consistency` | `dd-trace-ci:php-{latest}_bookworm-6` | Runs `tooling/generate-supported-configurations.sh` and verifies `metadata/supported-configurations.json` is up-to-date |

Runner: `arch:amd64` (all jobs in this pipeline are amd64-only)

Matrix:
- **Build & Test Tea**: PHP 7.0+ x {debug, debug-zts-asan (7.4+),
  nts, zts}. Pre-7.4 versions skip `debug-zts-asan` and use
  `debug-zts` instead.
- **ZAI Tests**: same matrix as TEA, plus UBSAN toolchain for `debug`
  variant on PHP 7.4+.
- **Extension Tea Tests**: PHP 7.0+ x {debug, debug-zts-asan
  (7.4+), nts, zts}. Pre-7.4 skips `debug-zts-asan`.
- **ZAI Shared Tests**: PHP 7.4, 8.0 only, `nts` variant only.
- **C components ASAN**: three images (centos-7, alpine, bookworm-6);
  ASAN toolchain only on Debian (bookworm).
- **C components UBSAN**: bookworm-6 only.
- **Configuration Consistency**: latest PHP version, single run.

## What It Tests

**TEA** (`tea/`) is a small C library that wraps PHP's Zend Engine for
test scaffolding. `Build & Test Tea` compiles it with cmake, runs its
own tests, and installs it to `tmp/tea/{variant}/` so downstream jobs
can reference it as `Tea_ROOT`.

**ZAI** (`zend_abstract_interface/`) contains C abstractions over Zend
internals (config, sandbox, interceptor, etc.). The ZAI tests link
against the TEA artifacts and exercise each ZAI component. The ASAN
variant uses `cmake/asan.cmake` and the UBSAN variant uses
`cmake/ubsan.cmake`.

**Extension Tea Tests** (`tests/tea/`) test ddtrace extension internals
using the TEA framework. They first build ddtrace.so (`make install`)
then build the cmake project in `tests/tea/`.

**ZAI Shared Tests** run on a special image (`php-{ver}-shared-ext`)
where PHP extensions like curl are shared (.so) rather than built-in.
This tests that ZAI works correctly when extensions are loaded via
`extension=curl.so`. Uses `-DRUN_SHARED_EXTS_TESTS=1` and
`TEA_INI_IGNORE=0`.

**C components** (`components/`) are standalone C modules tested with
Catch2. ASAN and UBSAN runs detect memory errors and undefined
behavior respectively.

**Configuration Consistency** verifies that the checked-in
`metadata/supported-configurations.json` matches what the generator
script produces from current source. Fails if they diverge.

## Build & Test Tea

### Full suite

```bash
.claude/ci/dockerh --cache tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
mkdir -p tmp/build-tea-debug
cd tmp/build-tea-debug
CMAKE_PREFIX_PATH=/opt/catch2 cmake \
  -DCMAKE_INSTALL_PREFIX=../../tmp/tea/debug \
  -DCMAKE_BUILD_TYPE=Debug \
  -DBUILD_TEA_TESTING=ON \
  ../../tea
make -j$(nproc) all
make install
make test ARGS="--output-on-failure"
'
```

Replace `8.3` and `debug` with the desired PHP version and variant.
For ASAN, add `-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake` and use
`--php debug-zts-asan` with a separate cache name.

### Single test

```bash
.claude/ci/dockerh --cache tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
cd tmp/build-tea-debug
make test ARGS="--output-on-failure -R tea_sapi"
'
```

The `-R` flag is a ctest regex filter on test names. Use
`ctest --test-dir tmp/build-tea-debug -N` to list available tests.

## Zend Abstract Interface Tests

`--overlayfs` is required: the ZAI cmake build links `components_rs`,
which triggers a cargo build of `libdd-libunwind-sys`.  That crate's
`build.rs` decided that running `git submodule update --init` from
inside a build script was a reasonable thing to do, so it writes into
`.git/modules/` — which fails on a read-only mount.

### Full suite

Requires TEA artifacts from the previous step to exist at
`tmp/tea/{variant}/`.

```bash
.claude/ci/dockerh --cache tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
mkdir -p tmp/build_zai && cd tmp/build_zai
CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/debug \
  cmake -DCMAKE_BUILD_TYPE=Debug -DBUILD_ZAI_TESTING=ON \
  -DPhpConfig_ROOT=$(php-config --prefix) \
  ../../zend_abstract_interface
make -j$(nproc) all
make test ARGS="--output-on-failure"
grep -e "=== Total [0-9]+ memory leaks detected ===" \
  Testing/Temporary/LastTest.log && exit 1 || true
'
```

For ASAN variant: add `-DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake`,
use `--php debug-zts-asan`, and a separate cache name.

### Single test

```bash
.claude/ci/dockerh --cache tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
cd tmp/build_zai
ctest --output-on-failure -R config
'
```

## Extension Tea Tests

### Full suite

Requires TEA artifacts at `tmp/tea/{variant}/`.

```bash
.claude/ci/dockerh --cache ext-tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make install
mkdir -p tmp/build_ext-tea && cd tmp/build_ext-tea
CMAKE_PREFIX_PATH=/opt/catch2 Tea_ROOT=../../tmp/tea/debug \
  cmake -DCMAKE_BUILD_TYPE=Debug -S ../../tests/tea
cmake --build . --parallel
make test ARGS="--output-on-failure"
grep -e "=== Total [0-9]+ memory leaks detected ===" \
  Testing/Temporary/LastTest.log && exit 1 || true
'
```

`--overlayfs` is needed because `make install` and cmake write into the
source tree (see [index.md](index.md) for details).

### Single test

```bash
.claude/ci/dockerh --cache ext-tea-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
cd tmp/build_ext-tea
ctest --output-on-failure -R "<test_name>"
'
```

## C Components ASAN / UBSAN

### Full suite (ASAN, bookworm)

```bash
.claude/ci/dockerh --cache components-asan \
  datadog/dd-trace-ci:bookworm-6 -- bash -c '
set -e
mkdir -p tmp/build_php_components_asan && cd tmp/build_php_components_asan
CMAKE_PREFIX_PATH=/opt/catch2 cmake \
  -DCMAKE_TOOLCHAIN_FILE=../../cmake/asan.cmake \
  -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON \
  ../../components
make -j$(nproc) all
make test ARGS="--output-on-failure"
'
```

For UBSAN, replace `asan` with `ubsan` in the toolchain file and
directory name. UBSAN in CI runs with `--repeat until-fail:10` to
catch non-deterministic issues (mainly in the channel component).

### Full suite (CentOS / Alpine)

On CentOS-7 and Alpine images there is no ASAN toolchain file, so
cmake runs without `-DCMAKE_TOOLCHAIN_FILE`:

```bash
.claude/ci/dockerh --cache components-centos \
  datadog/dd-trace-ci:centos-7 -- bash -c '
set -e
if [ -f "/opt/libuv/lib/pkgconfig/libuv.pc" ]; then
  export PKG_CONFIG_PATH="/opt/libuv/lib/pkgconfig:$PKG_CONFIG_PATH"
fi
if [ -d "/opt/catch2" ]; then
  export CMAKE_PREFIX_PATH=/opt/catch2
fi
mkdir -p tmp/build_php_components_asan && cd tmp/build_php_components_asan
cmake -DCMAKE_BUILD_TYPE=Debug -DDATADOG_PHP_TESTING=ON ../../components
make -j$(nproc) all
make test ARGS="--output-on-failure"
'
```

Replace `centos-7` with `php-compile-extension-alpine` for the Alpine
variant.

### Single test

```bash
cd tmp/build_php_components_asan
ctest --output-on-failure -R "<test_name>"
```

## Configuration Consistency

This job is quick and has no build step. It is unlikely to require
local reproduction, but if needed:

```bash
.claude/ci/dockerh --cache config-consistency --overlayfs \
  --php nts \
  datadog/dd-trace-ci:php-8.5_bookworm-6 -- bash -c '
bash tooling/generate-supported-configurations.sh
'
```

The script writes `ext/version.h` as a side effect; `--overlayfs`
absorbs this into the overlay volume.

If the output differs from the committed
`metadata/supported-configurations.json`, the CI job fails. Fix by
running the script locally and committing the result.

## Gotchas

- **TEA must be built before ZAI or Extension Tea Tests, using the same `--cache` name.**
  Each `dockerh` cache gets its own `tmp/` overlay. If the ZAI command uses a different
  `--cache` than the TEA build, `tmp/tea/debug/` will be empty and cmake fails with
  `Could not find a package configuration file provided by "Tea"`. The examples above
  all use `--cache tea-8.3-debug` for both TEA and ZAI.

- **The `--php` variant must match the TEA variant.** TEA artifacts
  at `tmp/tea/debug/` are built against the debug PHP ABI. Using them
  with `--php nts` (or vice versa) will produce link or runtime
  errors.

- **ZAI Shared Tests use a different image** (`php-{ver}-shared-ext`)
  that is not available for all PHP versions. Only 7.4 and 8.0 are
  tested. This image is not easily reproducible locally since the
  shared-ext images are custom CI builds.

- **C components tests do not require PHP.** The `bookworm-6` base
  image (no PHP version suffix) is sufficient. The centos-7 and alpine
  images need the `PKG_CONFIG_PATH` / `CMAKE_PREFIX_PATH` env vars
  for libuv and Catch2 respectively.

- **UBSAN test repeats are intentional.** The `--repeat until-fail:10`
  flag in CI catches non-deterministic UB in the channel component.
  Locally you can drop it for faster iteration.

- **Memory leak grep.** Both TEA and ZAI jobs grep `LastTest.log` for
  `=== Total [0-9]+ memory leaks detected ===` and fail if found.
  This catches PHP-level memory leaks that ctest itself does not
  treat as failures.
