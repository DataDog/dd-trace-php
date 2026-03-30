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

Correctness matrix: PHP 8.0--8.5 × {nts, zts}.
ASAN matrix: PHP {8.3, 8.4, 8.5} × {arm64, amd64}.

## What It Tests

Each job builds the profiler extension with `--features=trigger_time_sample`, then runs
PHP scripts that exercise profiling (allocations, wall/cpu time, exceptions, IO, timeline,
strange frames). The scripts output pprof files (zstd-compressed protobuf). The
`Datadog/prof-correctness/analyze` GitHub Action then checks each pprof against a JSON
expectations file.

Test cases (NTS): `allocations`, `time`, `strange_frames`, `timeline`, `exceptions`, `io`,
`allocation_time_combined`, plus `allocations` re-run with 1-byte sampling distance (with
and without `USE_ZEND_ALLOC=0`).

ZTS adds: `exceptions_zts`.

## Local Reproduction

Use `.claude/ci/dockerh` with the `datadog/dd-trace-ci:php-<VERSION>_bookworm-{N}` image
matching the PHP version under test (see `index.md` for image version and contents). The CI
uses clang-19 on ubuntu-24.04; clang-17 in the image works fine.

Actions jobs use `shivammathur/setup-php` instead, but the same `dd-trace-ci`
image is a suitable local substitute.

**Image naming:** use `php-8.1_bookworm-N` for PHP 8.1 tests, `php-8.3_bookworm-N` for
8.3, etc. — the image is tagged by PHP version, so the version in the tag must match the
PHP version being tested.

**Cache naming:** use a separate `--cache` name per `(php-version, phpts)` pair (e.g.
`profiler-8.1-zts`) to avoid mixing NTS and ZTS build artifacts.

### Build the profiler extension

`cargo rustc` must be run from the `profiling/` subdirectory (the workspace `profiler-release`
profile is defined in the repo root `Cargo.toml`, but the crate itself lives in `profiling/`).

**`CARGO_TARGET_DIR` must be set explicitly** to `/project/dd-trace-php/target`. Without
it the `cbindgen` build script inside `libdatadog` calls `cargo locate-project --workspace`,
which resolves to the `libdatadog/` submodule's own workspace (not the repo root), and
tries to create `libdatadog/target/include/datadog/library-config.h`. That path is inside
the read-only source mount with no writable overlay, causing a
`ReadOnlyFilesystem (os error 30)` panic. Pointing `CARGO_TARGET_DIR` at the already-
overlaid `/project/dd-trace-php/target` fixes it.

```bash
# NTS example (PHP 8.3)
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/target
cd profiling && cargo rustc --features=trigger_time_sample --profile profiler-release --crate-type=cdylib
'

# ZTS example (PHP 8.1) — note --php zts, matching image version, and separate cache name
dockerh --cache profiler-8.1-zts --php zts datadog/dd-trace-ci:php-8.1_bookworm-6 -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/target
cd profiling && cargo rustc --features=trigger_time_sample --profile profiler-release --crate-type=cdylib
'
```

Output: `/project/dd-trace-php/target/profiler-release/libdatadog_php_profiling.so`
(persisted in the host cache at `~/.cache/dd-ci/<CACHE-NAME>/target/`).

The second run reuses the build cache and completes in seconds. Never run `--clean-cache`
between iterations — the Rust build takes 5–15 minutes from scratch.

### Run a single test case

The `tmp/` directory is already a writable `dockerh` cache overlay, so
write pprof output there — no extra mounts needed:

```bash
dockerh --cache profiler-8.3-nts --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/target
export DD_PROFILING_LOG_LEVEL=warn   # use "trace" only when debugging — trace is verbose and slows execution
export DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=1
export DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=1
export DD_PROFILING_EXCEPTION_MESSAGE_ENABLED=1
export EXECUTION_TIME=3  # default is 10s; 3s is enough for local testing; applies to ALL time-based tests

TEST_CASE=allocations
OUT=/project/dd-trace-php/tmp/correctness/$TEST_CASE
mkdir -p $OUT
DD_PROFILING_OUTPUT_PPROF=$OUT/test.pprof \
  php -d extension=/project/dd-trace-php/target/profiler-release/libdatadog_php_profiling.so \
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

**Note:** the CI script checks for the `.lz4` extension (an older format), but the current
profiler outputs `.zst`. This means the CI "no profile" check always passes regardless of
whether a `.zst` file is produced. Locally, check for `.zst` if you want a meaningful
verification.

### Inspecting pprof output

The pprof files are zstd-compressed protobuf. Use `go tool pprof` (available in the
dd-trace-ci image) to inspect them. Pass `--user root` so `apt-get install` works:

```bash
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-7.3_bookworm-6 --user root -- bash -c '
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

For a debug (unoptimized) build:

```bash
cargo rustc --features=trigger_time_sample --profile dev --crate-type=cdylib
```

Output: `target/debug/libdatadog_php_profiling.so` (~144 MB vs ~20 MB for profiler-release).
Use the same `php -d extension=...` command, just point to the debug path.

## ZTS tests -- parallel PECL extension

The `exceptions_zts.php` test uses the `parallel` PECL extension. It is not installed in
the dd-trace-ci image by default. CI installs version `v1.2.7` via `shivammathur/setup-php`.

To install it locally, use the GitHub source URL (the default PECL channel may give a
different version and may fail without `libpcre2-dev`):

```bash
dockerh --cache profiler-8.1-zts --php zts datadog/dd-trace-ci:php-8.1_bookworm-6 --user root -- bash -c '
apt-get update -qq && apt-get install -y -qq libpcre2-dev
pecl install https://github.com/krakjoe/parallel/archive/refs/tags/v1.2.7.tar.gz
'
```

Note that `pecl install` writes `parallel.so` into the system PHP extensions directory,
which is read-only in the `dockerh` overlay cache. The installed `.so` does **not** persist
between container runs. To avoid reinstalling every time, copy it to the writable project
cache after installation:

```bash
cp $(php-config --extension-dir)/parallel.so /project/dd-trace-php/tmp/parallel.so
```

Then load it in subsequent runs with `-d extension=/project/dd-trace-php/tmp/parallel.so`.

When running `exceptions_zts.php`, load both extensions:

```bash
php -d extension=/project/dd-trace-php/target/profiler-release/libdatadog_php_profiling.so \
    -d extension=/project/dd-trace-php/tmp/parallel.so \
    /project/dd-trace-php/profiling/tests/correctness/exceptions_zts.php
```

## ASAN Build

Builds the profiler with AddressSanitizer using a pinned nightly Rust toolchain
and clang-17, then runs the `.phpt` test suite with `--asan`.

### Local reproduction

```bash
dockerh --cache profiler-asan-8.3-nts --php nts-asan \
  datadog/dd-trace-ci:php-8.3_bookworm-6 --user root --privileged -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/target
export CC=clang-17
export CFLAGS="-fsanitize=address -fno-omit-frame-pointer"
export LDFLAGS="-fsanitize=address -shared-libasan"
export RUSTC_LINKER=lld-17
RUST_TOOLCHAIN=nightly-2025-06-13

cd profiling
triplet=$(uname -m)-unknown-linux-gnu
RUSTFLAGS="-Zsanitizer=address" cargo +${RUST_TOOLCHAIN} build -Zbuild-std=std,panic_abort \
  --target $triplet --profile profiler-release
cp -v "$CARGO_TARGET_DIR/$triplet/profiler-release/libdatadog_php_profiling.so" \
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
- The `profiler-release` profile is defined in the workspace root `Cargo.toml`, not in
  `profiling/Cargo.toml`. It inherits from `release` with `panic = "abort"`.
- `dockerh` runs the container as your host UID so cache dirs are writable without any
  permission tricks. Pass `--user root` after the image name if you need to install
  packages with `apt-get`.
- CI checks for `.lz4` extension in the "no profile" test, but the current profiler
  outputs `.zst` (zstandard). Both are valid pprof compression formats.
