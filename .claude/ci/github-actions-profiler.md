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
| `Profiling ASAN/UBSAN Tests / PHP 8.5 {nts,zts} UBSAN ({arch})` | `arm-8core-linux` / `ubuntu-8-core-latest` | Builds profiler with UBSAN + runs `.phpt` profiling tests |

Correctness matrix: PHP 8.0+ × {nts, zts}.
ASAN matrix: PHP 8.3+ × {nts-asan, debug-zts-asan} × {arm64, amd64}.
UBSAN matrix: PHP 8.5 × {nts, zts} × {arm64, amd64}.

## What It Tests

Each job builds the profiler with
`make compile_profiler PROFILER_FEATURES=trigger_time_sample`, then runs PHP
scripts that exercise profiling (allocations, wall/cpu time, exceptions, IO, timeline,
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
uses clang-20 (`LLVM_VERSION` in `prof_correctness.yml`) on ubuntu-24.04; clang-21 in the
image works fine.

Actions jobs use `shivammathur/setup-php` instead, but the same `dd-trace-ci`
image is a suitable local substitute.

**Image naming:** use `php-8.1_bookworm-N` for PHP 8.1 tests, `php-8.3_bookworm-N` for
8.3, etc. — the image is tagged by PHP version, so the version in the tag must match the
PHP version being tested.

**Cache naming:** use a separate `--cache` name per `(php-version, phpts)` pair (e.g.
`profiler-8.1-zts`) to avoid mixing NTS and ZTS build artifacts.

### Build the profiler extension

Build through the top-level `Makefile`, the same way CI does. The build runs
out-of-tree in `tmp/build_profiler/` (writable under `dockerh`), and cargo's
target dir defaults to `tmp/build_profiler/target-profiling/`.

```bash
# NTS example (PHP 8.3)
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
cd /project/dd-trace-php && make compile_profiler PROFILER_FEATURES=trigger_time_sample
'

# ZTS example (PHP 8.1) — note --php zts, matching image version, and separate cache name
dockerh --cache profiler-8.1-zts --php zts datadog/dd-trace-ci:php-8.1_bookworm-11 -- bash -c '
cd /project/dd-trace-php && make compile_profiler PROFILER_FEATURES=trigger_time_sample
'
```

Output: `/project/dd-trace-php/tmp/build_profiler/modules/datadog-profiling.so`.

`PROFILER_FEATURES` adds Cargo features on top of `profiling` (default: none).
The correctness tests need `trigger_time_sample` (`strange_frames.php`); add
more comma-separated, e.g. `PROFILER_FEATURES=trigger_time_sample,debug_stats`.
Features are fixed at configure time, so remove `tmp/build_profiler/` after
changing them.
Release packages don't use these targets (`.gitlab/build-profiler.sh` runs
`phpize`/`configure` with no extra features).

The second run reuses the build cache and completes in seconds. Never run `--clean-cache`
between iterations — the Rust build takes 5–15 minutes from scratch.

### Run a single test case

The `tmp/` directory is already a writable `dockerh` cache overlay, so
write pprof output there — no extra mounts needed:

```bash
dockerh --cache profiler-8.3-nts --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-11 -- bash -c '
export DD_PROFILING_LOG_LEVEL=warn   # use "trace" only when debugging — trace is verbose and slows execution
export DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=1
export DD_PROFILING_EXPERIMENTAL_EXCEPTION_SAMPLING_DISTANCE=1
export DD_PROFILING_EXCEPTION_MESSAGE_ENABLED=1
export EXECUTION_TIME=3  # default is 10s; 3s is enough for local testing; applies to ALL time-based tests

TEST_CASE=allocations
OUT=/project/dd-trace-php/tmp/correctness/$TEST_CASE
mkdir -p $OUT
DD_PROFILING_OUTPUT_PPROF=$OUT/test.pprof \
  php -d extension=/project/dd-trace-php/tmp/build_profiler/modules/datadog-profiling.so \
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
dockerh --cache profiler-8.3-nts --php nts datadog/dd-trace-ci:php-7.3_bookworm-11 --user root -- bash -c '
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

For a debug (unoptimized) Rust build, add `RUST_DEBUG_BUILD=1` (use a separate
build dir, or remove `tmp/build_profiler/` first):

```bash
make compile_profiler RUST_DEBUG_BUILD=1 PROFILER_BUILD_SUFFIX=profiler_debug \
  PROFILER_FEATURES=trigger_time_sample
```

Output: `tmp/build_profiler_debug/modules/datadog-profiling.so`.

## ZTS tests -- parallel PECL extension

The `exceptions_zts.php` test uses the `parallel` PECL extension. The dd-trace-ci bookworm
images already include it for PHP 8+ ZTS builds (installed by `build-extensions.sh`), so no
extra setup is needed when reproducing locally with `dockerh`.

CI, on the other hand, runs on a bare `ubuntu-24.04` runner and installs PHP via
`shivammathur/setup-php`, which does not include `parallel` by default. The workflow
installs version `v1.2.7` from GitHub via the `extensions` matrix parameter
(`parallel-krakjoe/parallel@v1.2.7`).

## ASAN / UBSAN Builds

Both jobs live in `.github/workflows/prof_asan.yml` and build through the
top-level `Makefile` (out-of-tree, in `tmp/build_profiler*/`), not by running
`phpize`/`./configure` in the source root.

- **ASAN** (`prof-asan`): `make compile_profiler_asan`. Uses the pinned
  **stable** toolchain from `rust-toolchain.toml`; the target sets
  `RUSTC_BOOTSTRAP=1` so stable accepts `-Zsanitizer=address` and
  `-Zbuild-std=std,panic_abort` (std is rebuilt instrumented, which needs the
  `rust-src` component and an explicit `--target`). No nightly toolchain is
  used. Output:
  `tmp/build_profiler_asan/modules/datadog-profiling.so`.
- **UBSAN** (`prof-ubsan`): `make compile_profiler` with UBSAN `CFLAGS`/`LDFLAGS`
  and `-C link-arg=-fsanitize=...` in `RUSTFLAGS`. Output:
  `tmp/build_profiler/modules/datadog-profiling.so`.

`CC`/`CFLAGS`/`LDFLAGS` from the environment still apply to C code built by
cargo build scripts. The standalone profiler has no C of its own; the `.so` is
the Rust cdylib.

The workflow copies the `.so` into the extension dir rather than using
`make install_profiler`, because the install target also writes a
`datadog-profiling.ini` and the test step loads the extension with `-d
extension=...` (it would be loaded twice).

### Local reproduction (ASAN)

```bash
dockerh --cache profiler-asan-8.5-nts --php nts-asan \
  datadog/dd-trace-ci:php-8.5_bookworm-11 --user root --privileged -- bash -c '
export CARGO_TARGET_DIR=/project/dd-trace-php/tmp/build-cargo
export CC=clang-21
export CFLAGS="-fsanitize=address -fsanitize-address-use-after-scope -fno-omit-frame-pointer"
export LDFLAGS="-fsanitize=address -shared-libasan"

cd /project/dd-trace-php
make compile_profiler_asan
cp -v tmp/build_profiler_asan/modules/datadog-profiling.so \
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

Requires `--user root --privileged` — ASAN needs both. For UBSAN, use
`--php nts` (or `zts`), the UBSAN flags from the workflow, `make
compile_profiler`, and `LD_PRELOAD` the clang UBSAN runtime when running tests
(see the workflow).

## Gotchas

- **Expected test counts (PHP 8.5):** ASAN 47 total, 32 pass, 15 skip, 0 fail; UBSAN nts
  47 total, 35 pass, 12 skip, 0 fail. The skips are normal
  (platform/env conditions). A non-zero fail count indicates a real problem.
- The profiler is built from the root `datadog-php` crate (`Cargo.toml`, `--features profiling`);
  there is no `profiling/Cargo.toml`. The `profiler-release` profile is defined there too and
  inherits from `release` with `panic = "abort"`.
- `dockerh` runs the container as your host UID so cache dirs are writable without any
  permission tricks. Pass `--user root` after the image name if you need to install
  packages with `apt-get`.
- CI checks for `.lz4` extension in the "no profile" test, but the current profiler
  outputs `.zst` (zstandard). Both are valid pprof compression formats.
