# Benchmarks

## CI Jobs

**Source:**
- `.gitlab/generate-package.php` — declares `include: .gitlab/benchmarks.yml` and the pipeline stages
- `.gitlab/benchmarks.yml`: all benchmark job definitions; the microbenchmark
  steps, container and gate configs live in `.gitlab/benchmarks/` (see
  `.gitlab/benchmarks/README.md`)

| CI Job | Image | What it does |
|--------|-------|--------------|
| `benchmarks-tracer` | `$MICROBENCHMARKS_CI_IMAGE` (see `.gitlab/benchmarks.yml`; built in-repo from `.gitlab/benchmarks/container/`) | Runs PHP tracer microbenchmarks via the Benchmarking Platform framework, one `TRACER_PART` (phpbench, phpbench-opcache, tea) and, for the phpbench parts, one `PHPBENCH_SHARD` (1, 2) per matrix job; skips automatically if no tracer-relevant files changed |
| `benchmarks-appsec` | same | Runs appsec microbenchmarks; produces `candidate.tar.gz` and `baseline.tar.gz` artifacts |
| `benchmarks-profiler` | same | Runs profiler microbenchmarks |
| `benchmarks-pr-comment` | `$BENCHMARKING_PLATFORM_TOOLS_IMAGE` (see `.gitlab/benchmarks.yml`) | Posts the tracer comparison as a PR comment (bp-runner config generated inline; no checkout); needs `benchmarks-tracer`; allow_failure |
| `benchmarks-upload-bp-api` | same | Uploads the tracer results to the Benchmarking Platform API, in parallel with the comment; needs `benchmarks-tracer`; allow_failure |
| `benchmarks-pr-comment-appsec`, `benchmarks-upload-bp-api-appsec` | same | Same flow for AppSec, with its own comment header; needs `benchmarks-appsec` |
| `benchmarks-pr-comment-profiler`, `benchmarks-upload-bp-api-profiler` | same | Same flow for the profiler, with its own comment header; needs `benchmarks-profiler` |
| `build-benchmark-ci-images` | (built in-repo from `.gitlab/benchmarks/container/`) | Manual job that builds the microbenchmark CI image from `.gitlab/benchmarks/container/`; described in `.gitlab/benchmarks/README.md` § How to rebuild the CI Docker image |
| `build-llvm-bolt-align-image` | (built in-repo from `.gitlab/benchmarks/container/llvm-bolt-align/`) | Manual job that builds the static `llvm-bolt-align` tool image copied into the microbenchmark CI image; described in `.gitlab/benchmarks/README.md` § Rebuilding the `llvm-bolt-align` image |
| `macrobenchmarks: [{PHP_VERSION}]` | `486234852809.dkr.ecr.us-east-1.amazonaws.com/ci/benchmarking-platform:php_laravel-realworld` | Runs a Laravel Realworld application under k6 load at three traffic levels; PHP 7.4 and 8.1 |
| `check-big-regressions` | `$BENCHMARKING_PLATFORM_TOOLS_IMAGE` | Post-benchmark gate: fails if `benchmarks-tracer` results contain a regression above threshold, or if any part's candidate results (or baseline results, when there is a baseline branch) are missing |
| `check-slo-breaches` | (benchmarking-platform-tools template) | Post-macrobenchmark gate: evaluates SLO breaches |
| `notify-slo-breaches` | (benchmarking-platform-tools template) | Posts SLO breach notifications to `#guild-dd-php` Slack channel |

Runner: `runner:apm-k8s-tweaked-metal` (microbenchmarks); `runner:apm-k8s-same-cpu`
(macrobenchmarks); `arch:amd64` (gate and report jobs).

**Trigger rules:**
- `benchmarks-tracer` — runs on every push; has an early-exit guard: if none of
  `ext/`, `tracer/`, `src/`, `components/`, `components-rs/`,
  `zend_abstract_interface/`, `tests/Benchmarks/`, `benchmark/`, `tea/`,
  `.gitlab/benchmarks/` or `.gitlab/benchmarks.yml`
  changed relative to `master`, exits 0 and `check-big-regressions`
  also skips.
- `benchmarks-appsec` — auto-runs when `appsec/src/**/*` changed;
  available as a manual job otherwise.
- `benchmarks-profiler` — auto-runs when `profiling/**/*` changed;
  available as a manual job otherwise.
- `macrobenchmarks` — automatic on `master` and release branches; manual otherwise.

## What It Tests

Microbenchmarks measure overhead of the tracer, appsec, and profiler components
using the Benchmarking Platform framework (steps in `.gitlab/benchmarks/`). The
scenario name is passed as `BP_SCENARIO` to `bp-runner`.

Macrobenchmarks run a realistic PHP application (Laravel Realworld) under k6 load
at three traffic levels and upload results to S3. They depend on compiled extension
and `datadog-setup.php` artifacts.

## Local Reproduction

Benchmark jobs run on dedicated performance hardware that is not accessible
outside CI. **Local runs produce numbers incomparable to CI results**, but
they are useful for before/after comparisons on the same machine and for
verifying that benchmark code runs without errors.

The CI `benchmarks-tracer` job ultimately runs `make benchmarks`,
`make benchmarks_opcache`, and `make benchmarks_tea` (via
`benchmark/runall.sh`). You can run these same targets locally inside a
dev container.

### Running the benchmarks

The `.gitlab/benchmarks/` directory is the CI orchestrator: it checks out
the candidate and baseline, builds and BOLT-aligns them, and runs
`benchmark/runall.sh`, which calls `make benchmarks` and friends. You do not
need `bp-runner` to run those targets locally. To run the whole flow, see
"Full CI flow with BOLT alignment" below. Three approaches for the targets,
from simplest to most flexible:

**Approach 1 — `make benchmarks` (clean cache)**

Builds and benchmarks in one step from a clean overlay. The default autotools
configure includes the Rust sidecar in the link, so no special handling is
needed. It builds the default, tracer-only extension, not the combined
tracer + profiler + AppSec extension that CI measures (see "Full CI flow with
BOLT alignment" below), so its numbers are not comparable to CI's.

```bash
.claude/ci/dockerh --cache bench-82 --clean-cache --overlayfs --root \
    datadog/dd-trace-ci:php-8.2_bookworm-11 \
    -e DD_TRACE_AUTOLOAD_NO_COMPILE=true \
    -- bash -c '
git config --global --add safe.directory /project/dd-trace-php
make -j$(nproc) composer_tests_update
make -j$(nproc) benchmarks FILTER=SpanBench
'
```

First run compiles Rust + C (~10 min). Subsequent runs (without
`--clean-cache`) reuse the cached build artifacts and are fast.

**Approach 2 — `compile_extension.sh` + manual install**

Use this if you already have a `ddtrace.so` built by
`compile_extension.sh` (see
[compile-artifacts.md](compile-artifacts.md) § Local Reproduction)
or downloaded from CI. This approach requires manual `cp` because
`compile_extension.sh` uses `--enable-ddtrace-rust-library-split`
(see caveats above).

Build step (skip if `.so` already in overlay or downloaded from CI):

```bash
.claude/ci/dockerh --cache bench-82-split --overlayfs --root \
    datadog/dd-trace-ci:php-8.2_bookworm-11 \
    -e SHARED=1 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash .gitlab/compile_extension.sh
```

Benchmark step (every run — the ext dir is outside the overlay):

```bash
.claude/ci/dockerh --cache bench-82-split --overlayfs --root \
    datadog/dd-trace-ci:php-8.2_bookworm-11 \
    -e SHARED=1 \
    -e DD_TRACE_AUTOLOAD_NO_COMPILE=true \
    -- bash -c '
git config --global --add safe.directory /project/dd-trace-php
cp tmp/build_extension/modules/ddtrace.so \
    $(php-config --extension-dir)/ddtrace.so
make install_ini
make -j$(nproc) composer_tests_update
make benchmarks_run_dependencies ASSUME_COMPILED=1
make call_benchmarks FILTER=SpanBench
'
```

On subsequent runs (deps already in overlay) you can drop the
`composer_tests_update` and `benchmarks_run_dependencies` lines.

**Approach 3 — Download `.so` from CI**

Fastest when you don't need to modify the extension itself. See
[compile-artifacts.md](compile-artifacts.md) and the
`download-artifacts` script.

```bash
# Download the "compile extension: debug" artifact for PHP 8.2
tooling/bin/download-artifacts \
    --job-name "compile extension: debug [8.2, amd64]" \
    -o /tmp/bench-ext

# Place it in the overlay via bind-mount
.claude/ci/dockerh --cache bench-82-dl --overlayfs --root \
    datadog/dd-trace-ci:php-8.2_bookworm-11 \
    -v /tmp/bench-ext:/tmp/bench-ext:ro \
    -- bash -c '
mkdir -p tmp/build_extension/modules
cp /tmp/bench-ext/tmp/build_extension/modules/ddtrace.so \
    tmp/build_extension/modules/ddtrace.so
'
```

Then run benchmarks as in Approach 2 (using `--cache bench-82-dl`).

Replace `FILTER=SpanBench` with any PHPBench filter, or remove it
to run all suites. For OPcache benchmarks use `call_benchmarks_opcache`.

### Before/after comparison

1. Check out the baseline (e.g. `origin/master`), build (or download
   from CI), run benchmarks, save the CSV.
2. Check out your branch, build, run benchmarks, save the CSV.
3. Compare the two CSVs (the `subject` column identifies each
   benchmark; `time_avg` is the average time in microseconds).

Use separate `--cache` names (e.g. `bench-82-baseline` and
`bench-82-candidate`) to avoid rebuilding when switching branches.

### Full CI flow with BOLT alignment

Runs `.gitlab/benchmarks/steps/run-benchmarks.sh` as the CI job does: clones
the candidate and its merge-base with `master` into `/app`, builds both,
aligns them with `llvm-bolt-align`, and runs both. The scripts write `/app`,
`/etc/hosts` and a sysctl, so run them as root in the CI image
(`$MICROBENCHMARKS_CI_IMAGE`, see `.gitlab/benchmarks.yml`) on an amd64 Linux
machine. The registry may be unreachable from the machine you build on; copy
the image over with `docker save | ssh ... docker load`.

1. The scripts clone from GitHub, so push the branch, or point the clone at a
   local checkout: mount it at `/src` with local branches `$BRANCH` and
   `master`, and redirect the URL (see `drive.sh` below).
2. `drive.sh`, mounted into the container (`BP_SCENARIO` is `profiler`,
   `tracer` or `appsec`; `profiler` is the shortest):
   ```bash
   set -ex
   git config --global --add safe.directory '*'
   git config --global url."/src/".insteadOf \
     https://github.com/DataDog/dd-trace-php/
   # Fewer than 48 CPUs: run-revision.sh pins to 34-47; ignore the pinning.
   mkdir -p /shim
   printf '#!/bin/sh\nif [ "$1" = -c ]; then shift 2; fi\nexec "$@"\n' \
     > /shim/taskset
   chmod +x /shim/taskset
   export PATH=/shim:$PATH
   export CI_COMMIT_REF_NAME=$BRANCH CI_PROJECT_NAME=dd-trace-php
   export CI_COMMIT_SHA=$(git -C /src rev-parse "$BRANCH")
   export CI_PROJECT_DIR=/out ARTIFACTS_DIR=/out/artifacts
   export BP_SCENARIO=profiler BASELINE_BRANCH=master
   export COMPOSER_ALLOW_SUPERUSER=1 DD_TRACE_AUTOLOAD_NO_COMPILE=true
   mkdir -p "$ARTIFACTS_DIR"
   sysctl -w kernel.perf_event_paranoid=2 || true
   /src/.gitlab/benchmarks/steps/run-benchmarks.sh
   ```
3. ```bash
   docker run --rm --privileged -e BRANCH=my-branch \
     -v /path/to/checkout:/src:ro -v /path/to/out:/out \
     -v $PWD/drive.sh:/drive.sh:ro IMAGE bash /drive.sh
   ```
4. The aligned count is in the log (`BOLT-ALIGN: pinned N functions (100%)`;
   anything well below 100% means the two builds name functions differently).
   Results are in `/out/artifacts`: `candidate-*`, `baseline-*`,
   `bolt-mode.txt` and `bolt/`. With fewer than 48 CPUs the timings only show
   that the pipeline works.

Verified for the `profiler` scenario only. The `tracer` and `appsec` scenarios
also start mysql and redis, and the `bp-runner` form of the job additionally
needs a Datadog agent and API key.

### Services needed by some benchmarks

Some benchmark suites (e.g. `PDOBench`, `PHPRedisBench`) need MySQL
and Redis, and `make composer_tests_update` needs MySQL too: Laravel's
post-install step creates a database on the `mysql-integration` host.

The `datadog/dd-trace-ci` images used in the approaches above have neither
server, so those commands fail in `composer_tests_update` there. The
microbenchmark CI image (`$MICROBENCHMARKS_CI_IMAGE`) has both installed but
not running. In it, start them before `composer_tests_update` and the
benchmarks, as the job's setup step does:

```bash
service mysql start
redis-server --daemonize yes
echo "127.0.0.1 mysql-integration" >> /etc/hosts
echo "127.0.0.1 redis-integration" >> /etc/hosts
```

Or skip those two suites when running the benchmarks (this does not help
`composer_tests_update`, which still needs MySQL):
`make call_benchmarks FILTER='PDO(*SKIP)(*F)|Redis(*SKIP)(*F)|.'`

## Investigating a Regression

When `check-big-regressions` fails, download the `reports/` artifact from the
`benchmarks-tracer` job. The gate runs `.gitlab/benchmarks/bp-runner.fail-on-regression.yml`. To read the job log directly:

```bash
curl -s -H "PRIVATE-TOKEN: $GITLAB_PERSONAL_ACCESS_TOKEN" \
  "https://gitlab.ddbuild.io/api/v4/projects/355/jobs/<JOB_ID>/trace"
```

## Gotchas

- If the job log says "No tracer-related file changes detected - skipping benchmark
  execution", `check-big-regressions` also skips. This is expected, not a failure.

- `macrobenchmarks` has `allow_failure: true` — it will not block the pipeline.

- The macrobenchmark image (`php_laravel-realworld`) is maintained on the
  `php/laravel-realworld` branch of `DataDog/benchmarking-platform`, distinct from
  the microbenchmark image (built in-repo from `.gitlab/benchmarks/container/`).

- The PHP extension dir (`/opt/php/...`) is outside the overlayfs
  project tree, so `ddtrace.so` must be installed into it on every
  container run. The built `.so` in `tmp/build_extension/modules/`
  **does** persist in the overlay, so the install step is just a `cp`.
  This affects Approaches 2 and 3; Approach 1 handles it automatically.

- `git config --global --add safe.directory /project/dd-trace-php`
  must be run each container invocation (PHPBench uses git internally).

- When using Approaches 2 or 3, do **not** use `make install` or
  `make benchmarks` — `compile_extension.sh` runs `make static` which
  configures with `--enable-ddtrace-rust-library-split`, telling
  autotools to omit the Rust sidecar from the link. That configure
  state persists in the overlay, so a subsequent `make install`
  rebuilds a `.so` without the sidecar (undefined `ddtrace_sidecar`
  symbol). Instead, `cp` the `.so` manually and use
  `make call_benchmarks`. Approach 1 does not have this problem
  because the default configure includes the sidecar.
