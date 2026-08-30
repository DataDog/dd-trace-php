# Profiler Tests (GitHub Actions)

## CI Jobs

**Source:**
- `.github/workflows/prof_correctness.yml` — correctness job definition,
  matrix, build and run steps
- `Datadog/prof-correctness/analyze@main` — external action that
  decompresses pprof output and checks it against JSON expectations
- `.github/workflows/prof_asan.yml` — ASAN job definition and matrix

| CI Job | Runner | What it does |
|--------|--------|-------------|
| `Profiling correctness / prof-correctness ({ver}, nts)` | `ubuntu-24.04` | Builds profiler + runs NTS correctness test cases |
| `Profiling correctness / prof-correctness ({ver}, zts)` | `ubuntu-24.04` | Same + `exceptions_zts` (requires `parallel` PECL extension) |
| `Profiling ASAN Tests / prof-asan ({ver}, {arch})` | `arm-8core-linux` / `ubuntu-8-core-latest` | Builds profiler with ASAN + runs `.phpt` profiling tests |

Correctness matrix: PHP 8.0+ × {nts, zts}.
ASAN matrix: PHP 8.3+ × {arm64, amd64}.

## What It Tests

Each job builds through phpize/configure/Make with
`DDTRACE_PROFILING_FEATURES=trigger_time_sample`, then runs PHP scripts that exercise
profiling (allocations, wall/cpu time, exceptions, IO, timeline, strange frames). PHP
8.5 correctness cells use combined `ddtrace.so`; older cells retain the standalone
profiler. The scripts output pprof files (zstd-compressed protobuf). The
`Datadog/prof-correctness/analyze` GitHub Action then checks each pprof against a JSON
expectations file. The PHP 8.5 NTS combined cell also runs tracer/profiler runtime-ID
integration with profiling both enabled and disabled and checks that closing a web root
span enqueues its endpoint information in the profiler. It then rebuilds a standalone
profiler and verifies that loading it alongside `ddtrace.so` fails in both load orders.
The PHP 8.4 NTS standalone cell also creates an OpenTelemetry SDK tracer and active span
to verify that the userland SDK and standalone profiler coexist.

Test cases (NTS): `allocations`, `time`, `strange_frames`, `timeline`, `exceptions`, `io`,
`allocation_time_combined`, plus `allocations` re-run with 1-byte sampling distance (with
and without `USE_ZEND_ALLOC=0`).

ZTS adds: `exceptions_zts`.

## Local Reproduction

Use `.claude/ci/dockerh` with the `datadog/dd-trace-ci:php-<VERSION>_bookworm-10` image
matching the PHP version under test (see `index.md` for image contents). The CI
installs and uses clang-20 on ubuntu-24.04.

Actions jobs use `shivammathur/setup-php` instead, but the same `dd-trace-ci`
image is a suitable local substitute.

**Image naming:** use `php-8.1_bookworm-10` for PHP 8.1 tests,
`php-8.3_bookworm-10` for 8.3, etc. The version in the tag must match the PHP version
being tested.

**Cache naming:** use a separate `--cache` name per `(php-version, phpts)` pair (e.g.
`profiler-8.1-zts`) to avoid mixing NTS and ZTS build artifacts.

### Build the profiler extension

Loadable artifacts must go through phpize/configure/Make from the repository root.
Do not load a Cargo target-directory cdylib.

```bash
# Standalone NTS example (PHP 8.3)
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-8.3_bookworm-10 -- bash -c '
cd /project/dd-trace-php
phpize
DDTRACE_PROFILING_FEATURES=trigger_time_sample \
  ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling --disable-ddtrace-rust-debug
make -j"$(nproc)"
'

# Combined ZTS example (PHP 8.5)
dockerh --cache profiler-8.5-zts --php zts datadog/dd-trace-ci:php-8.5_bookworm-10 -- bash -c '
cd /project/dd-trace-php
phpize
DDTRACE_PROFILING_FEATURES=trigger_time_sample \
  ./configure --enable-ddtrace-tracer --enable-ddtrace-profiling --disable-ddtrace-rust-debug
make -j"$(nproc)"
'
```

The supported outputs are `modules/datadog-profiling.so` and
`modules/ddtrace.so`, respectively. Use separate caches for PHP versions and NTS/ZTS
variants.

### Run a single test case

The `tmp/` directory is already a writable `dockerh` cache overlay, so
write pprof output there — no extra mounts needed:

```bash
dockerh --cache profiler-8.3-nts --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-10 -- bash -c '
export DD_PROFILING_LOG_LEVEL=warn   # use "trace" only when debugging — trace is verbose and slows execution
export DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=1
export DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=1
export DD_PROFILING_EXCEPTION_MESSAGE_ENABLED=1
export EXECUTION_TIME=3  # default is 10s; 3s is enough for local testing; applies to ALL time-based tests

TEST_CASE=allocations
OUT=/project/dd-trace-php/tmp/correctness/$TEST_CASE
mkdir -p $OUT
DD_PROFILING_OUTPUT_PPROF=$OUT/test.pprof \
  php -d extension=/project/dd-trace-php/modules/datadog-profiling.so \
      /project/dd-trace-php/profiling/tests/correctness/$TEST_CASE.php
ls -la $OUT/
'
```

The output file is `test.pprof.1.zst` (zstd-compressed pprof protobuf).

For `strange_frames`, the test is instant (no loop) and does not need `EXECUTION_TIME`.

**Speed tip:** when investigating a single failing test case, run only that script and
inspect with `go tool pprof -top` (see below) rather than running the full suite.

### Run the "no profile" check

CI also verifies that with `DD_PROFILING_ENABLED=Off` no pprof file is produced:

```bash
export DD_PROFILING_ENABLED=Off
# ... run the same php command ...
# Verify test.pprof.1.zst does NOT exist
```

### Inspecting pprof output

The pprof files are zstd-compressed protobuf. Use `go tool pprof` (available in the
dd-trace-ci image) to inspect them. Pass `--user root` so `apt-get install` works:

```bash
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-7.3_bookworm-10 --user root -- bash -c '
apt-get update -qq > /dev/null 2>&1 && apt-get install -y -qq zstd > /dev/null 2>&1

PPROF_DIR=/project/dd-trace-php/tmp/correctness/allocations
zstd -d $PPROF_DIR/test.pprof.1.zst -o $PPROF_DIR/test.pprof.1

# Top functions by alloc-size
go tool pprof -top -sample_index=alloc-size $PPROF_DIR/test.pprof.1

# Full stack traces with labels
go tool pprof -traces -sample_index=alloc-size $PPROF_DIR/test.pprof.1
'
```

Available `-sample_index` values (matching the pprof value types):
`sample`, `wall-time`, `cpu-time`, `alloc-samples`, `alloc-size`, `timeline`,
`exception-samples`, `file-io-read-size`, `file-io-write-size`,
`socket-read-size`, `socket-write-size`, and their `-time` / `-samples` variants.

### Understanding the JSON expectations

Each `profiling/tests/correctness/<test_case>.json` defines expected stack distributions.
Structure:

```json
{
  "scale_by_duration": true,
  "test_name": "php_allocations",
  "stacks": [
    {
      "profile-type": "alloc-size",
      "stack-content": [
        {
          "regular_expression": "<?php;main;a;standard\\|str_repeat$",
          "percent": 33,
          "error_margin": 5
        }
      ]
    }
  ]
}
```

- **profile-type**: which pprof sample type to check (maps to `-sample_index`)
- **regular_expression**: regex matched against the semicolon-joined stack trace
  (bottom-to-top: `<?php;main;a;standard|str_repeat`)
- **percent**: expected percentage of total value for matching stacks
- **error_margin**: allowed deviation in percentage points
- **labels**: (optional) expected pprof labels on matching samples (e.g., exception type,
  thread name). Can use `values` for exact match or `values_regex` for regex match.

The `Datadog/prof-correctness/analyze` action decompresses the pprof files in the given
directory, aggregates samples by stack trace per profile-type, and checks that each
expected stack's percentage falls within `percent +/- error_margin`.

To manually verify: use `go tool pprof -top -sample_index=<profile-type>` and check that
the cumulative percentages of the listed functions match the JSON expectations.

## `trigger_time_sample` Feature

This cargo feature (not for production) exposes a PHP function
`Datadog\Profiling\trigger_time_sample()` that forces an immediate time sample capture.
Used by `strange_frames.php` to get a deterministic single-sample profile for testing
frame name formatting. The implementation is in `profiling/src/capi.rs` and
`profiling/src/php_ffi.c`.

## Debug Build

Select Rust debug mode through configure, then consume the Make output:

```bash
phpize
DDTRACE_PROFILING_FEATURES=trigger_time_sample \
  ./configure --disable-ddtrace-tracer --enable-ddtrace-profiling --enable-ddtrace-rust-debug
make -j"$(nproc)"
```

The artifact remains `modules/datadog-profiling.so`. Profiling PHPT and correctness
expectations are intended for optimized builds.

## ZTS tests -- parallel PECL extension

The `exceptions_zts.php` test uses the `parallel` PECL extension. The dd-trace-ci bookworm
images already include it for PHP 8+ ZTS builds (installed by `build-extensions.sh`), so no
extra setup is needed when reproducing locally with `dockerh`.

CI, on the other hand, runs on a bare `ubuntu-24.04` runner and installs PHP via
`shivammathur/setup-php`, which does not include `parallel` by default. The workflow
installs version `v1.2.7` from GitHub via the `extensions` matrix parameter
(`parallel-krakjoe/parallel@v1.2.7`).

## ASAN Build

Builds the profiler with AddressSanitizer using a pinned nightly Rust toolchain
and clang-20, then runs the `.phpt` test suite with `--asan`.

### Local reproduction

```bash
dockerh --cache profiler-asan-8.3-nts --php nts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-10 --user root --privileged -- bash -c '
cd /project/dd-trace-php
export CC=clang-20
export CFLAGS="-fsanitize=address -fsanitize-address-use-after-scope -fno-omit-frame-pointer"
export LDFLAGS="-fsanitize=address -shared-libasan"
export RUSTC_LINKER=lld-20
rustup override set nightly-2025-06-13
export RUSTFLAGS="-Zsanitizer=address -C force-frame-pointers=yes"
export DDTRACE_PROFILING_TARGET="$(uname -m)-unknown-linux-gnu"
export DDTRACE_PROFILING_CARGO_BUILD_FLAGS="-Zbuild-std=std,panic_abort"
phpize
./configure --disable-ddtrace-tracer --enable-ddtrace-profiling --disable-ddtrace-rust-debug
make -j"$(nproc)"
cp -v modules/datadog-profiling.so \
  "$(php-config --extension-dir)/datadog-profiling.so"

# run-tests.php writes temp files next to .phpt files, so both must be in a writable dir.
# Use the tmp/ overlay which dockerh mounts writable over the read-only checkout.
PHPT_RUN=/project/dd-trace-php/tmp/phpt-run
rm -rf "$PHPT_RUN" && mkdir -p "$PHPT_RUN"
cp $(php-config --prefix)/lib/php/build/run-tests.php "$PHPT_RUN/"
cp -r /project/dd-trace-php/profiling/tests/phpt "$PHPT_RUN/"
cd "$PHPT_RUN"
DD_PROFILING_OUTPUT_PPROF=/tmp/pprof \
  php run-tests.php -j$(nproc) --show-diff --asan -d extension=datadog-profiling.so phpt
'
```

Requires `--user root --privileged` — ASAN needs both.

The nightly toolchain version (`nightly-2025-06-13`) is pinned in
`.github/workflows/prof_asan.yml`, not in `profiling/rust-toolchain.toml`. Check
the workflow file for the current pinned version.

## Gotchas

- **Expected ASAN test counts:** 39 total, ~27 pass, ~12 skip (30%), 0 fail. The skips are normal
  (platform/env conditions). A non-zero fail count indicates a real problem.
- `dockerh` runs the container as your host UID so cache dirs are writable without any
  permission tricks. Pass `--user root` after the image name if you need to install
  packages with `apt-get`.
