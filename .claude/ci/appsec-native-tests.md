# Appsec Native Linux Tests

## CI Jobs

**Source:** `.gitlab/generate-appsec.php` — generates the appsec-trigger
child pipeline; all job `script:` sections are defined inline in this
file.

| CI Job | Image | What it does |
|--------|-------|-------------|
| `test appsec extension: [{ver}, {arch}, debug]` | `datadog/dd-trace-ci:php-{ver}_bookworm-6` | Builds appsec PHP extension + runs phpunit `.phpt` tests |
| `test appsec extension: [{ver}, {arch}, debug-zts]` | same | ZTS variant |
| `test appsec extension: [{ver}, {arch}, debug-zts-asan]` | same | ASAN variant (PHP 7.4+) |
| `test appsec helper asan` | `datadog/dd-trace-ci:bookworm-6` | Builds C++ helper with ASAN, runs gtest suite |
| `appsec lint` | `datadog/dd-trace-ci:php-8.3_bookworm-6` | clang-format + clang-tidy |
| `appsec code coverage` | `datadog/dd-trace-ci:php-8.3_bookworm-6` | Coverage instrumented build (not needed locally) |

Runner: `arch:amd64` + `arch:arm64`
Matrix: PHP 7.0--8.5 × {debug, debug-zts, debug-zts-asan (7.4+)}

The `{arch}` dimension only controls the GitLab runner tag. It has no
effect on the Docker image or commands run. On macOS (Apple Silicon),
prefer the `arm64` variant using `--platform linux/arm64` — pass it as a
Docker option between the image name and `--`:

```bash
.claude/ci/dockerh --cache appsec-ext-8.3-debug-arm64 --writable-tree --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 --platform linux/arm64 -- bash -c '...'
```

## Why `--writable-tree` is needed

These builds use `dockerh --writable-tree` (see `index.md`) instead of
the default read-only checkout. Two cmake files write generated headers
back into the source tree:

- `appsec/cmake/extension.cmake` — writes `src/extension/version.h`
  into `CMAKE_CURRENT_SOURCE_DIR`
- `appsec/third_party/libddwaf/CMakeLists.txt` — writes
  `src/version.hpp` into `CMAKE_CURRENT_SOURCE_DIR` (helper builds)

Both writes would fail with a read-only mount. `--writable-tree` makes
the checkout writable while cache overlays for `appsec/build` and `tmp/`
still persist to `~/.cache/dd-ci/<NAME>/`.

## Extension tests

### Full suite

All commands are run from the repo root. Replace `8.3` with the desired
PHP version (`7.0`--`8.5`) and `debug` with the desired variant
(`debug`, `debug-zts`, `debug-zts-asan`).

```bash
.claude/ci/dockerh --cache appsec-ext-8.3-debug --writable-tree --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
sudo apt-get update -qq && sudo apt-get install -y -qq \
  libc++-17-dev libc++abi-17-dev > /dev/null 2>&1

# Build and run appsec extension tests
# (cmake's xtest target builds ddtrace.so automatically as a dependency)
mkdir -p appsec/build
cd appsec/build
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_HELPER=OFF \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++" -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
  -DDD_APPSEC_TESTING=ON
ASAN_OPTIONS=malloc_context_size=0 make -j$(nproc) xtest
'
```

`clang-tidy-17` is installed by CI's shared `before_script` template but is not needed
for extension tests — only for `appsec lint`. Omitting it saves ~10 seconds of apt time.

`ASAN_OPTIONS=malloc_context_size=0` is passed by CI unconditionally (even for non-ASAN
builds) and is harmless when ASAN is not active.

The `appsec/build` and `tmp/` overlays persist between runs, so
subsequent invocations skip the Boost compile and incremental-build only
what changed. To start from scratch: add `--clean-cache` before
`--writable-tree`.

For the ASAN variant, add `-DENABLE_ASAN=ON` to cmake and prepend
`ASAN_OPTIONS=malloc_context_size=0` to the make command. Use a separate
cache name (e.g. `appsec-ext-8.3-debug-asan`) since the cmake cache is
incompatible:

```bash
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_HELPER=OFF \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++" -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++" \
  -DDD_APPSEC_TESTING=ON -DENABLE_ASAN=ON
ASAN_OPTIONS=malloc_context_size=0 make -j4 xtest
```

### Single test file

Once the build directory exists (cmake has already been run), re-run a
single test using the `TESTS` env var. Paths are relative to `appsec/`
(the cmake source directory). Drop `clang-tidy-17` from the apt install
to save ~10 seconds:

```bash
.claude/ci/dockerh --cache appsec-ext-8.3-debug --writable-tree --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
sudo apt-get update -qq && sudo apt-get install -y -qq \
  libc++-17-dev libc++abi-17-dev > /dev/null 2>&1
cd appsec/build
TESTS=tests/extension/waf_timeout_default.phpt ASAN_OPTIONS=malloc_context_size=0 make xtest
'
```

Multiple test files can be passed space-separated in `TESTS`. Glob
patterns also work (e.g., `TESTS="tests/extension/user_req_*.phpt"`).

## Extension tests via Gradle

`appsec/tests/integration/build.gradle` exposes `xtest` tasks that run
the phpt suite inside Docker, reusing the same volumes as the integration
tests. This is an alternative that avoids writing into the host tree
entirely.

Working directory: `appsec/tests/integration/`

### Full suite for one target

```bash
./gradlew xtest8.3-debug --info
```

Replace version and variant to match the integration test matrix
(`release`, `debug`, `release-zts`, …). Musl targets have no `xtest`
task.

### Single test file

Pass the `tests` property (path relative to `appsec/`, space-separated,
globs work):

```bash
./gradlew xtest8.3-debug --info \
    -Ptests="tests/extension/waf_timeout_default.phpt"

./gradlew xtest8.3-debug --info \
    -Ptests="tests/extension/user_req_*.phpt"
```

### Build caching

Gradle stores artifacts in Docker volumes (`php-tracer-*`,
`php-appsec-*`). First run compiles Boost from source; subsequent runs
reuse it. To force a full rebuild:

```bash
docker volume rm php-appsec-8.3-debug php-tracer-8.3-debug
```

## Helper tests (C++ ASAN)

The C++ helper tests use the `bookworm-6` image (no PHP needed). The
binary is a gtest executable. `appsec/build-helper` is not in the
`dockerh` cache overlays, so pass it explicitly as a `-v` bind mount
pointing into the cache dir. Create the host dir first:

```bash
mkdir -p ~/.cache/dd-ci/appsec-helper/appsec/build-helper
```

Note: `--user root` overrides the host-uid `--user` that `dockerh`
injects automatically — Docker honors the last `--user` flag, so this
is correct and intentional.

### Full suite

```bash
.claude/ci/dockerh --cache appsec-helper --writable-tree \
  datadog/dd-trace-ci:bookworm-6 \
  --user root \
  -v ~/.cache/dd-ci/appsec-helper/appsec/build-helper:/project/dd-trace-php/appsec/build-helper \
  -- bash -c '
set -e
apt-get update -qq && apt-get install -y -qq \
  libc++-17-dev libc++abi-17-dev > /dev/null 2>&1
# Required: libddwaf submodule dir is owned by root (--writable-tree +
# --user root), and cmake's ExternalProject runs git inside it.
git config --global --add safe.directory \
  /project/dd-trace-php/appsec/third_party/libddwaf
mkdir -p appsec/build-helper
cd appsec/build-helper
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF \
  -DDD_APPSEC_ENABLE_COVERAGE=OFF -DDD_APPSEC_TESTING=ON \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++ -fsanitize=address -fsanitize=leak -DASAN_BUILD" \
  -DCMAKE_C_FLAGS="-fsanitize=address -fsanitize=leak -DASAN_BUILD" \
  -DCMAKE_EXE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak" \
  -DCMAKE_MODULE_LINKER_FLAGS="-fsanitize=address -fsanitize=leak"
make -j$(nproc) ddappsec_helper_test
cd /project/dd-trace-php
./appsec/build-helper/tests/helper/ddappsec_helper_test
'
```

`clang-tidy-17` is not needed here — omitting it saves ~5–8 seconds of
apt time. CI installs it via a shared `before_script` template.

CI also passes `-DBOOST_CACHE_PREFIX=$CI_PROJECT_DIR/boost-cache` and
`-DCLANG_TIDY=/usr/bin/run-clang-tidy-17` to cmake. The first only
affects where the Boost cache lands (not a correctness issue locally).
The second means CI silently runs clang-tidy checks during the helper
build that are skipped locally.

### Single test

Once built, the pre-built binary can be run without reinstalling apt
packages:

```bash
.claude/ci/dockerh --cache appsec-helper --writable-tree \
  datadog/dd-trace-ci:bookworm-6 \
  --user root \
  -v ~/.cache/dd-ci/appsec-helper/appsec/build-helper:/project/dd-trace-php/appsec/build-helper \
  -- bash -c '
./appsec/build-helper/tests/helper/ddappsec_helper_test \
  --gtest_filter="WafTest.TraceAttributesAreSent"
'
```

The filter supports wildcards: `--gtest_filter="WafTest.*"` runs all
tests in the `WafTest` suite. Use `--gtest_list_tests` to see available
tests.

### Without ASAN

For a faster non-ASAN build (useful when debugging test logic rather than
memory issues), drop the sanitizer flags:

```bash
cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_BUILD_EXTENSION=OFF \
  -DDD_APPSEC_TESTING=ON \
  -DCMAKE_CXX_FLAGS="-stdlib=libc++" -DCMAKE_CXX_LINK_FLAGS="-stdlib=libc++"
```

## Appsec lint

This can easily run locally:

```bash
mkdir -p appsec/build
cd appsec/build
if [[ ! -f CMakeCache.txt ]]; then
    cmake .. -DCMAKE_BUILD_TYPE=Debug -DDD_APPSEC_DDTRACE_ALT
fi
make format_fix  # fix clang-format violation
make tidy_fix    # fix clang-tidy violations
```

## Gotchas

- The build directory (`appsec/build`) must be **inside the repo**
  (in-tree). Using an out-of-tree build directory fails because
  `appsec/cmake/extension.cmake` writes `src/extension/version.h` into
  `CMAKE_CURRENT_SOURCE_DIR`.

- The first build takes several minutes because Boost is compiled from
  source. Subsequent builds reuse the cached Boost in
  `~/.cache/dd-ci/<NAME>/appsec/build/boost_cache/` (extension tests) or
  in the `php-appsec-boost-cache` Docker volume (Gradle).

- `appsec/build-helper` (helper tests) is **not** a `dockerh` cache
  overlay. Pass it explicitly as `-v ~/.cache/dd-ci/appsec-helper/appsec/build-helper:...`
  (see the Helper tests section). Files are owned by root because helper
  tests run with `--user root`; clean up with
  `docker run --rm -v ~/.cache/dd-ci/appsec-helper/appsec:/w alpine rm -rf /w/build-helper`.

- The `libc++-17-dev` and `libc++abi-17-dev` packages must be installed
  in every new container — the cmake cache references libc++ headers and
  will fail to compile without them.

- When switching PHP versions or variants, use a distinct `--cache` name
  per version/variant (e.g. `appsec-ext-8.3-debug` vs
  `appsec-ext-7.4-debug`).

- Avoid `--clean-cache` unless absolutely necessary — it destroys the
  Boost build cache, which takes 10+ minutes to rebuild. To force only a
  cmake reconfigure, delete `CMakeCache.txt` directly:
  ```bash
  rm ~/.cache/dd-ci/appsec-ext-8.3-debug/appsec/build/CMakeCache.txt
  ```
  Boost stays intact in `boost_cache/`.
