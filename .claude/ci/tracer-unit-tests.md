# Tracer Unit Tests and Native Extension Tests

## CI Jobs

**Source:**
- `.gitlab/generate-tracer.php` — generates the tracer-trigger child
  pipeline; defines all job matrices and `script:` sections inline
- `.gitlab/compile_extension.sh` — compiles ddtrace.so (used by the
  `compile extension: debug` prerequisite)
- `Makefile` — defines the `test_c`, `test_unit`, `test_opcache`,
  `test_extension_ci`, etc. targets

| CI Job | Image | What it does |
|--------|-------|-------------|
| `compile extension: debug` | `dd-trace-ci:php-{ver}_bookworm-6` | Compiles ddtrace.so in debug mode; produces artifact consumed by all test jobs below |
| `compile extension: debug-zts-asan` | same | Compiles ddtrace.so with ASAN+ZTS; used by ASAN test jobs |
| `Unit tests: [{ver}]` | `dd-trace-ci:php-{ver}_bookworm-6` | Runs PHPUnit `--testsuite=unit` |
| `API unit tests: [{ver}]` | same | Runs PHPUnit API unit tests |
| `test_extension_ci: [{ver}]` | same | Runs .phpt extension tests + valgrind wrapper, with test-agent |
| `PHP Language Tests: [{ver}]` | same | Runs the upstream PHP test suite with ddtrace loaded; uses an xfail list |
| `Opcache tests: [{ver}]` | same | Runs .phpt tests in `tests/opcache/` with opcache.so loaded |
| `xDebug tests: [{ver}, {xdebug_ver}]` | same | Runs xdebug-specific .phpt tests + unit tests with xdebug loaded |
| `Disabled test_c run: [{ver}]` | same | Runs `make test_c_disabled`; ignores test exit code, only fails if `.out` files contain leaks, segfaults, or assertion failures |
| `Internal api randomized tests: [{ver}]` | same | Stress-tests the internal tracing API with random calls |
| `test_auto_instrumentation: [{ver}]` | same | PHPUnit `--testsuite=auto-instrumentation` |
| `test_composer: [{ver}]` | same | PHPUnit `--testsuite=composer-tests` |
| `test_integration: [{ver}]` | same | PHPUnit `--testsuite=integration` (no external services beyond test-agent + mongodb) |
| `test_distributed_tracing: [{ver}, {sapi}]` | same | PHPUnit `--testsuite=distributed-tracing` with cli-server and cgi-fcgi SAPIs |
| `ASAN test_c: [{ver}, {arch}]` | same | .phpt extension tests under ASAN (ZTS debug build) |
| `ASAN Internal api randomized tests: [{ver}, {arch}]` | same | Internal API stress test under ASAN |
| `ASAN init hook tests: [{ver}]` | same | Tests init hook mechanism under ASAN |
| `ASAN test_c with multiple observers: [{ver}]` | same | .phpt tests with `zend_test.observer` enabled under ASAN (PHP 8.0+) |
| `ASAN Opcache tests: [{ver}]` | same | Opcache .phpt tests under ASAN |

Runner: `arch:amd64` for all test jobs. Compile jobs also run on
`arch:arm64`.

Matrix:
- **Non-ASAN jobs**: PHP 7.0--8.5, amd64 only.
- **ASAN jobs**: PHP 7.4--8.5 x {amd64, arm64} for `ASAN test_c`;
  PHP 7.4--8.5 amd64-only for other ASAN jobs. `ASAN test_c with
  multiple observers` is PHP 8.0+ only.
- **xDebug tests**: specific (PHP, xdebug) version pairs; see the
  `$xdebug_test_matrix` array in `generate-tracer.php`. Xdebug is
  not yet supported on PHP 8.5.
- **`test_distributed_tracing`**: PHP 7.0--8.5 x {cli-server,
  cgi-fcgi}. The cgi-fcgi variant sets
  `DD_DISTRIBUTED_TRACING=false`.
- **`test_auto_instrumentation`, `test_composer`, `test_integration`**:
  PHP 7.0--8.5, amd64.

## Quick start: build once, run many

Build the extension and install PHPUnit prerequisites once:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e; make -j$(nproc) all; make install_all;
composer update --no-interaction; make generate'
```

Then reuse the cache for any test target:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  -e DD_TRACE_ASSUME_COMPILED=1 -- bash -c 'make test_unit'
```

Replace `test_unit` with `test_c`, `test_opcache`,
`test_internal_api_randomized`, etc.

## Prerequisites

All test jobs (except compile jobs) depend on the
`compile extension: debug` (or `debug-zts-asan`) artifact. CI
downloads the pre-built `ddtrace.so` and places it in
`tmp/build_extension/modules/`. When reproducing locally, you must
either:

1. Build ddtrace.so inside the container first (see "Building the
   extension locally" below), or
2. Reuse a cached build from a previous `dockerh` run (the `tmp/`
   overlay persists).

**`git submodule update --init libdatadog` is a hard requirement.**
If the `libdatadog` submodule is not initialised, `configure`/`cmake`
will fail with a missing directory. This must be run inside the
container before `make all`.

PHPUnit-based jobs (`test_unit`, `test_integration`,
`test_auto_instrumentation`, `test_composer`,
`test_distributed_tracing`) also require `Prepare code` artifacts
(generated bridge files) and a `composer update`.

**`DD_TRACE_ASSUME_COMPILED=1`** tells the Makefile that the extension
is already compiled and installed — it skips the `configure`/build
step that would otherwise run as a prerequisite of `make test_*`. Pass
it as a container env var (`-e DD_TRACE_ASSUME_COMPILED=1`) for all
PHPUnit-based test runs after a prior build. Without it, the Makefile
may attempt to recompile the extension in a context where that would
conflict with the cached build.

## Building the extension locally

See [building-locally.md](building-locally.md#for-test-jobs-bookworm-debug-build)
for the build command and common gotchas (submodules, CARGO_HOME, etc.).

`make install` (not `make install_all`) is sufficient before `test_c`
and `test_opcache` — those jobs load `ddtrace.so` directly from the
build tree via `-d extension=$(SO_FILE)`. PHPUnit jobs use
`install_all` (which also installs ini files) and trigger it
automatically via their `global_test_run_dependencies` prerequisite.

The `tmp/` cache overlay persists the build artifacts for subsequent
runs.

## .phpt Extension Tests (test_c)

### Full suite

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make -j$(nproc) all
make test_c
'
```

CI runs this with test-agent, httpbin, and request-replayer service
containers. Locally, tests that contact these services will be
skipped (most .phpt tests are self-contained).

### Single test

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
make test_c TESTS=tests/ext/sandbox/auto_flush.phpt
'
```

`TESTS` accepts space-separated paths or a directory. Paths are
relative to the repo root.

### Disabled test_c run

The CI job runs `make test_c_disabled` — **not**
`DD_TRACE_CLI_ENABLED=0 make test_c`. These are not equivalent.

The Makefile target ignores all test failures (`|| true`) and only
fails if `.out` files contain: memory leak flush messages, segfaults,
or `assert()` failures. **~450 test failures are expected by design**
(tests that call hooks, `active_span()`, etc. fail because CLI tracing
is disabled). The grep pattern for flush detection has been stale since
August 2022 and never matches.

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
make test_c_disabled
'
```

For the ASAN variant, see the [ASAN Tests](#asan-tests) section.

## PHPUnit Unit Tests

### Full suite

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make -j$(nproc) all
make install_all
composer update --no-interaction
make generate
make test_unit
'
```

### Single test

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
make test_unit FILTER=testSomething
'
```

Use `FILTER=` (not `TESTS="--filter=..."`) — the Makefile appends
`--filter=$(FILTER)` itself, and passing `--filter` twice gives
unpredictable PHPUnit behaviour.

To also restrict to a specific file, add it via `TESTS`:
```bash
make test_unit TESTS=tests/Unit/SomeTest.php FILTER=testSomething
```

## Opcache Tests

### Full suite

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make -j$(nproc) all
make test_opcache
'
```

This runs .phpt tests from `tests/opcache/` with both ddtrace.so and
opcache.so loaded. On PHP < 8.5 the `-d zend_extension=opcache.so`
flag is passed automatically.

### Single test

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
make test_opcache TESTS=tests/opcache/some_test.phpt
'
```

## Internal API Randomized Tests

Requires a compiled `ddtrace.so` (see [Prerequisites](#prerequisites)).

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make -j$(nproc) all
make test_internal_api_randomized
'
```

For the ASAN variant, see the [ASAN Tests](#asan-tests) section.

## PHP Language Tests

### Full suite

Start service containers, then run the test suite:

```bash
PROJECT=tracer-lang-83
docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml \
  up -d test-agent request-replayer

.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --root --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network ${PROJECT}_default \
  -e DD_AGENT_HOST=test-agent \
  -e DD_TRACE_AGENT_PORT=9126 \
  -- bash -c '
set -e
make -j$(nproc) all install_all
export XFAIL_LIST=dockerfiles/ci/xfail_tests/8.3.list
export CI_PROJECT_DIR=/project/dd-trace-php
export PHP_MAJOR_MINOR=8.3
export DD_TRACE_STARTUP_LOGS=0
export DD_TRACE_WARN_CALL_STACK_DEPTH=0
export DD_TRACE_GIT_METADATA_ENABLED=0
export SKIP_ONLINE_TESTS=1
.gitlab/run_php_language_tests.sh
'

docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml down
```

This runs the upstream PHP test suite (from `/usr/local/src/php/`)
with ddtrace loaded. Expected failures are listed in
`dockerfiles/ci/xfail_tests/{ver}.list`.

`--root` is required: the script deletes xfail `.phpt` files, modifies
test files in-place, and `run-tests.php` writes helper scripts — all
inside `/usr/local/src/php/`.

## xDebug Tests

### Full suite

```bash
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --root --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
make -j$(nproc) all install_all
php /usr/local/src/php/run-tests.php -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP \
  -p $(which php) --show-all \
  -d zend_extension=xdebug-3.3.2.so \
  tests/xdebug/3.0.0
'
```

Replace `3.3.2` with the xdebug version to test. The test directory
is `tests/xdebug/2.7.2`, `tests/xdebug/2.9.2`, `tests/xdebug/2.9.5`,
or `tests/xdebug/3.0.0` (xdebug 3.x all use the `3.0.0` dir). The
xdebug `.so` files are pre-installed in the CI images.

Some xdebug versions also run `make test_unit` with the xdebug
extension loaded via `TEST_EXTRA_INI`.

## test_auto_instrumentation / test_composer / test_integration

### Prerequisites — service containers

These jobs need `test-agent`, `request-replayer`, and
`httpbin-integration`. Use a project name matching your cache key:

```bash
docker compose -p tracer-integ-83 -f .claude/ci/docker-compose.services.yml \
  up -d test-agent request-replayer httpbin-integration
```

Wait a few seconds for the test-agent to be ready before running tests.

### Full suite

```bash
.claude/ci/dockerh --cache tracer-integ-83 --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network tracer-integ-83_default \
  -e DD_TRACE_ASSUME_COMPILED=1 \
  -e DDAGENT_HOSTNAME=test-agent \
  -e DD_AGENT_HOST=test-agent \
  -e DD_TRACE_AGENT_PORT=9126 \
  -e HTTPBIN_HOSTNAME=httpbin-integration \
  -e HTTPBIN_PORT=8080 \
  -e DATADOG_HAVE_DEV_ENV=1 \
  -- bash -c '
set -e
make -j$(nproc) all
make install_all
composer update --no-interaction
make generate
DD_TRACE_AGENT_TIMEOUT=1000 make test_auto_instrumentation
'
```

`RUST_DEBUG_BUILD=1` (seen in CI) passes `--enable-ddtrace-rust-debug`
to `./configure`, but this is auto-detected from the PHP debug binary
and only matters during the configure step — it is not needed on test
target invocations after a `--php debug` build.

Replace `test_auto_instrumentation` with `test_composer` or
`test_integration`.

Note: `test_integration` additionally sets `DD_TRACE_AGENT_RETRIES=3
DD_TRACE_AGENT_FLUSH_INTERVAL=333 DD_AGENT_HOST=test-agent
DD_TRACE_AGENT_PORT=9126` via `TEST_EXTRA_ENV` in the Makefile.
`test_composer` and `test_auto_instrumentation` do not — they rely on
the container-level `DD_AGENT_HOST` and `DD_TRACE_AGENT_PORT` env
vars set via `-e` above.

### Cleanup

```bash
docker compose -p tracer-integ-83 -f .claude/ci/docker-compose.services.yml down
```

### Single test

```bash
DD_TRACE_AGENT_TIMEOUT=1000 make test_integration \
  FILTER=testSomething
```

## test_distributed_tracing

Requires the same service containers as the previous section. Start
them if not already running (see `test_auto_instrumentation` section).

### Full suite

```bash
.claude/ci/dockerh --cache tracer-integ-83 --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network tracer-integ-83_default \
  -e DD_TRACE_ASSUME_COMPILED=1 \
  -e DDAGENT_HOSTNAME=test-agent \
  -e DD_AGENT_HOST=test-agent \
  -e DD_TRACE_AGENT_PORT=9126 \
  -e HTTPBIN_HOSTNAME=httpbin-integration \
  -e HTTPBIN_PORT=8080 \
  -e DATADOG_HAVE_DEV_ENV=1 \
  -e DD_TRACE_TEST_SAPI=cli-server \
  -- bash -c '
set -e
make -j$(nproc) all
make install_all
composer update --no-interaction
make generate
DD_TRACE_AGENT_TIMEOUT=1000 make test_distributed_tracing
'
```

CI runs this twice per PHP version: once with `cli-server` SAPI and
once with `cgi-fcgi` SAPI. For the `cgi-fcgi` variant, change the
env vars:
```
  -e DD_TRACE_TEST_SAPI=cgi-fcgi \
  -e DD_DISTRIBUTED_TRACING=false \
```

## ASAN Tests

All ASAN jobs use `--php debug-zts-asan` and a **separate cache** from
the normal debug build (e.g., `tracer-8.3-asan`). The `BUILD_DIR`
(`tmp/build_extension`) is **not** suffixed when `ASAN=1`, so reusing
a debug cache for ASAN will corrupt object files and produce crashes or
silent wrong behaviour.

### Step 1 — Build (shared for all ASAN targets)

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
export COMPILE_ASAN=1
make -j$(nproc) all
'
```

`COMPILE_ASAN=1` must be set explicitly — it is not auto-detected. It
enables `-fsanitize=address` in the Rust sidecar build. `ASAN=1` is
auto-detected from the PHP binary, so the explicit export is optional.

The `make` configure step automatically adds `--enable-ddtrace-sanitize`
when `ASAN=1` is detected, which adds `-fsanitize=address
-fno-omit-frame-pointer` to the C extension's CFLAGS and linker flags.

### ASAN test_c

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
set -e
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
make test_c
'
```

CI caps `MAX_TEST_PARALLELISM=2` for ASAN jobs (vs 8 normally) due to
memory overhead. Running with full `nproc` locally risks OOM kills or
false-positive sanitizer failures. Consider setting:
```bash
make test_c MAX_TEST_PARALLELISM=2
```

Under ASAN, `run-tests.php --asan` sets `SKIP_ASAN=1` in the test
environment. Tests with `--XLEAK--` sections are skipped entirely (not
run). Tests that use `getenv("SKIP_ASAN")` in `--SKIPIF--` sections
(e.g., crashtracker tests) are also skipped.

### ASAN Internal api randomized tests

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
make test_internal_api_randomized
'
```

### ASAN init hook tests

These tests live in `tests/C2PHP/` and are run via `make
test_with_init_hook`. The only test in that directory
(`get_context_distributed_tracing_test.phpt`) requires
`httpbin-integration` to be reachable. It has **no `--SKIPIF--`
section** — if httpbin is missing or not yet ready, the test throws an
exception (hard failure, not a skip).

Start httpbin before running:
```bash
docker compose -p tracer-asan-83 -f .claude/ci/docker-compose.services.yml \
  up -d httpbin-integration
```

Then run:
```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network tracer-asan-83_default \
  -e HTTPBIN_HOSTNAME=httpbin-integration \
  -e HTTPBIN_PORT=8080 \
  -- bash -c '
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
make test_with_init_hook
'
```

Note: `make test_with_init_hook` loads the extension via
`-d datadog.trace.sources_path=$(TRACER_SOURCE_DIR)`, which is a
different load mechanism from `test_c` (which uses `-d
extension=$(SO_FILE)` directly).

### ASAN test_c with multiple observers (PHP 8.0+ only)

The `zend_test.observer` INI flags used by this target are undefined on
PHP 7.x — do not run against PHP 7.x.

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
make test_c_observer
'
```

### ASAN Opcache tests

```bash
.claude/ci/dockerh --cache tracer-8.3-asan --overlayfs \
  --php debug-zts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
make test_opcache
'
```

## Gotchas

- **The `debug` PHP build is the default on PATH** in CI images, but
  `dockerh --php debug` still explicitly calls `switch-php debug`.
  Always use `--php` to be explicit.

- **ASAN builds must use ZTS.** The ASAN PHP build is `debug-zts-asan`,
  not `debug-asan`. NTS ASAN builds do not exist in the CI images.

- **`--overlayfs` is required** — many steps write to the source tree
  (`make generate`, `composer update`, `git checkout`).  See
  [index.md](index.md).

- **`make install_all` writes outside the overlay.** `install_all`
  copies `ddtrace.so` to `/opt/php/debug/lib/php/extensions/` and
  `ddtrace.ini` to `/opt/php/debug/conf.d/` — both outside
  `/project/dd-trace-php`, so they are not persisted by `--overlayfs`.
  Each new `dockerh` invocation must re-run `make install_all` even
  when the compiled artifacts are cached.

- **`test_extension_ci` uses a valgrind wrapper.** The Makefile
  prepends `tests/ext/valgrind` to `$PATH`, which intercepts `php`
  calls to run them under valgrind. This makes the job significantly
  slower and is specific to CI.

- **`PHP Language Tests` has retry:2 in CI.** These tests are
  inherently flaky due to timing-sensitive PHP runtime tests. The CI
  job retries up to 2 times on script failure.

- **`test_integration` talks to test-agent on port 9126** and mongodb.
  `test_composer`, `test_auto_instrumentation`, and
  `test_distributed_tracing` also need the test-agent — unlike
  `test_integration`, their Makefile targets do not set `DD_AGENT_HOST`
  internally, so it must be provided via a container-level `-e`
  argument. Without test-agent running and reachable, these tests
  produce errors (not skips).

- **`request-replayer` is required for integration tests.** The
  test-agent Docker image is configured with
  `DD_TRACE_AGENT_URL=http://request-replayer:80`. Tests that exercise
  trace forwarding (e.g., `OrphansTest`) directly connect to
  `request-replayer` by hostname. Start it alongside `test-agent`.

- **`ASSERT_NO_MEMLEAKS` only exists in CI.** This is defined in
  `generate-tracer.php` and appended directly to the GitLab `script:`
  lines. It is not in the Makefile. Running `make test_unit` or `make
  test_api_unit` locally does not pipe output through any memory-leak
  grep.

- **Service containers are not started by `dockerh`.** Start them
  with `docker compose -p <project> -f .claude/ci/docker-compose.services.yml up -d`
  before running `dockerh`, and connect the PHP container with
  `--network <project>_default`. `request-replayer` must be started
  alongside `test-agent` — the test-agent forwards to it, and some
  tests (e.g. `OrphansTest`) connect to it directly.

- **xdebug `.so` files are pre-installed in CI images.** They live at
  paths like `/opt/php/debug/lib/php/extensions/*/xdebug-3.3.2.so`.
  If reproducing locally with a non-CI PHP build, you would need to
  install xdebug separately.
