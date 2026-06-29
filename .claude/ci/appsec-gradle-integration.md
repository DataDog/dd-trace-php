# AppSec Gradle Integration Tests

## CI Jobs

**Source:** `.gitlab/generate-appsec.php` — generates the appsec-trigger
child pipeline; all job `script:` sections are defined inline in this
file.

| CI Job | Image | What it does |
|--------|-------|-------------|
| `appsec integration tests: [{target}]` | `docker:29.4.0-noble` | Gradle integration tests with Rust helper by default (release/zts/musl variants) |
| `appsec integration tests (ssi): [{target}]` | same | SSI mode (PHP 8.3 only), Rust helper by default |
| `helper-rust build and test` | same | `cargo fmt --check` + build + unit tests |
| `helper-rust code coverage` | same | Unit test coverage via `cargo-llvm-cov` |
| `helper-rust integration coverage` | same | Integration coverage collection (not needed locally) |

Runner: `docker-in-docker:amd64`
Matrix: PHP 7.0+ × release/debug/zts/musl/ssi (varies by job group)

**Important:** `testX.Y-debug` are not gradle targets that are run on CI. They
may, however, be useful for debugging.

CI passes `TERM=dumb` and `--scan -Pbuildscan` to Gradle. `TERM=dumb`
suppresses progress animations in CI logs; both flags are optional
locally.

## Prerequisites

- JDK 17+. If not available via your system package manager, use SDKMAN
  (`sdk install java 17`) or download from
  https://download.oracle.com/java/17/archive/.
- Docker daemon running

## Working Directory

All `./gradlew` commands run from:

```
appsec/tests/integration/
```

## Running Tests

### Full suite for one PHP target

```bash
./gradlew test8.3-debug --info
```

### Single test (fastest feedback loop)

```bash
./gradlew test8.3-debug --info \
    --tests "com.datadog.appsec.php.integration.Apache2FpmTests.Pool environment"
```

The `--tests` filter accepts:
- Full method: `"com.datadog.appsec.php.integration.Apache2FpmTests.Pool environment"`
- Class only: `"*Apache2FpmTests*"` or `"com.datadog.appsec.php.integration.Apache2FpmTests"`
- Wildcard: `"*FpmTests*"`

### Rust helper (default)

```bash
./gradlew test8.3-debug --info \
    --tests "com.datadog.appsec.php.integration.Apache2FpmTests.Pool environment"
```

This builds the Rust helper via the `buildHelperRust` task (musl build,
works on both glibc and musl targets), stores the binary in the
`php-helper-rust` Docker volume, and mounts it as
`/helper-rust/libddappsec-helper.so`.

## Image Tags

By default, Gradle resolves Docker images via pinned SHA256 digests in `gradle/tag_mappings.gradle`. To use floating tags (locally-built images or latest from Docker Hub):

```bash
./gradlew test8.3-debug -PfloatingImageTags --info
```

## Available Gradle Tasks

### Test tasks

Pattern: `test{version}-{variant}`

| Variant | Notes |
|---|---|
| `release` | Standard build |
| `debug` | Debug build (assertions enabled) |
| `release-zts` | Thread-safe build |
| `release-musl` | Alpine/musl (only `8.5-release-musl`) |
| `release-ssi` / `debug-ssi` | SSI mode (only PHP 8.3) |

Full list: `./gradlew tasks --group=Verification`

### Helper-rust tasks

| Task | Description |
|---|---|
| `buildHelperRust` | Build helper-rust with musl (universal binary). Output in `php-helper-rust` volume. |
| `testHelperRust` | `cargo fmt --check` + `cargo build --release` + `cargo test --release` (runs inside `php-deps` image) |
| `coverageHelperRust` | Unit test coverage via `cargo-llvm-cov`. Output: `php-helper-rust-coverage` volume. |
| `buildHelperRustWithCoverage` | Build with `-C instrument-coverage` for integration coverage collection. |
| `generateHelperRustIntegrationCoverage` | Merge `.profraw` files into lcov after integration run. |

### Build tasks

| Task | Description |
|---|---|
| `buildTracer-{v}-{var}` | Build ddtrace.so for given PHP version/variant |
| `buildAppsec-{v}-{var}` | Build ddappsec.so (C++ extension + helper) |
| `buildHelperRust` | Build Rust helper (musl, universal) |
| `buildLibddwaf` | Build libddwaf shared library |

### Other tasks

| Task | Description |
|---|---|
| `loadCaches` | Restore Docker volume caches from tarball |
| `saveCaches` | Save Docker volume caches to tarball |
| `clean` | Delete build directory and clean Docker volumes |
| `check` | Run all test tasks |

All tasks: `./gradlew tasks --all`

## Interactive Container (runMain)

Start a test container without running tests (for manual debugging):

```bash
./gradlew runMain8.3-release -PtestClass=com.datadog.appsec.php.integration.Apache2FpmTests
```

The `-PtestClass` property is required (the task is not created without
it). Add `-PhelperBinary=...` to bind-mount an explicit helper binary.

SSI variant:

```bash
./gradlew runMain8.3-release-ssi -PtestClass=com.datadog.appsec.php.integration.Apache2FpmTests
```

## Logs

After a test run, logs are in:

```
build/test-logs/<TestClassName>-<version>-<variant>/
```

For example:

```
build/test-logs/com.datadog.appsec.php.integration.Apache2FpmTests-8.3-debug/
├── access.log
├── appsec.log         # PHP extension appsec log
├── error.log          # Apache error log
├── helper.log         # Rust helper process log
├── php_error.log
├── php_fpm_error.log
└── sidecar.log
```

## Musl/Alpine Target

The `test8.5-release-musl` target uses an Alpine-based nginx+fpm image. Tests tagged with `@Tag("musl")` are included; untagged tests are excluded.

```bash
./gradlew test8.5-release-musl --info
```

The `buildHelperRust` task already produces a musl-linked binary (built on Alpine with `cargo +nightly`, using LLVM libunwind). The `patchelf --remove-needed libc.musl-*` step makes it load on both musl and glibc systems.

## CI Job Mapping

| CI Job | Gradle Command |
|---|---|
| `appsec integration tests: [test8.3-release]` | `./gradlew test8.3-release` |
| `appsec integration tests (ssi): [test8.3-release-ssi]` | `./gradlew test8.3-release-ssi` |
| `helper-rust build and test` | `./gradlew testHelperRust` |
| `helper-rust code coverage` | `./gradlew coverageHelperRust` |
| `helper-rust integration coverage` | `./gradlew buildHelperRustWithCoverage` then integration test with `-PuseHelperRustCoverage` |

CI also passes `--scan -Pbuildscan` for Gradle build scans, which is optional locally.

## Docker Volumes

Gradle uses named Docker volumes for build artifacts and caches. Key volumes:

| Volume | Contents |
|---|---|
| `php-helper-rust` | `libddappsec-helper.so` (Rust helper binary) |
| `php-tracer-{v}-{var}` | Built `ddtrace.so` |
| `php-appsec-{v}-{var}` | Built `ddappsec.so` |
| `php-tracer-cargo-cache` | Cargo registry cache |
| `php-tracer-cargo-cache-git` | Cargo git cache |
| `php-appsec-boost-cache` | Boost build cache |
| `php-helper-rust-coverage` | Coverage-instrumented binary + profraw files |

To force a rebuild, remove the relevant volume:

```bash
docker volume rm php-helper-rust
```

To clean everything:

```bash
./gradlew clean
```

## Debugging

Attach a Java debugger to the test runner:

```bash
./gradlew test8.3-debug --tests "*Apache2FpmTests*" --debug-jvm
```

Enable PHP Xdebug in the test container:

```bash
./gradlew test8.3-debug --tests "*Apache2FpmTests*" -PXDEBUG=1
```

## Expected skips

A significant number of tests are skipped on any given target — this is
normal. Skip conditions are `@EnabledIf` guards in the test classes:

| Test class | Skips on | Reason |
|---|---|---|
| `FrankenphpClassicTests`, `FrankenphpWorkerTests` | anything except `8.4-zts` | Requires ZTS + PHP 8.4 |
| `Laravel8xTests` | anything except `7.4` (NTS) | Requires PHP 7.4 non-ZTS |
| `Symfony62Tests` | anything except `8.1` (NTS) | Requires PHP 8.1 non-ZTS |
| `RaspSqliTests` | no MySQL service | Requires a running MySQL |
| `SsiStableConfigTests` | non-SSI variants | Requires `-DSSI=true` |

On `test8.3-debug` expect ~67 skips out of ~300 tests; all are expected.

## Test report

After a run, the HTML report is at:

```
appsec/tests/integration/build/reports/tests/test8.3-debug/index.html
```

(Replace `test8.3-debug` with your target.) Open in a browser for a
structured pass/fail/skip breakdown.

# Debugging

Run gradle with `--debug-jvm`. This will stop for the debugger, indicating so in the output.
When you see the message, start jdb in a tmux session.

If you need to inspect sidecar/helper or PHP issues:

* Put a breakpoint in jdb that stops the test in the appropriate place (usually
  just before a request is executed).
* Inside the test container (determine first its id), attach gdb to sidecar
  (`pref -f dd-ipc-helper`) or to PHP (usually an apache or an FPM worker -- if
  you're investigating code run during processes it will not be the master
  process). sidecar requires as a first command `file /proc/<pid>/exe`).
* See [gdb.md](../gdb.md) for more information on how to run gdb. Always read
  this file before attempting to use gdb.

## Gotchas

- The `test` task itself is disabled (`tasks['test'].enabled = false`). Use versioned tasks like `test8.3-debug`.
- Docker images are pulled from `docker.io/datadog/dd-appsec-php-ci`. Without `-PfloatingImageTags`, images are resolved by SHA256 digest from `gradle/tag_mappings.gradle`. If a digest is not locally available, Docker will pull it.
- The `buildHelperRust` task uses the `nginx-fpm-php-8.5-release-musl` image (Alpine with Rust nightly). This image must be available locally or pullable.
- On first run, Gradle downloads its wrapper, dependencies, and Docker images. Expect 5-10 minutes. Subsequent runs with warm caches take ~20-50 seconds for a single test.
- **c-ares DNS failure in Alpine containers.** Alpine's `curl` and `git` use
  c-ares for DNS, which fails to resolve hosts when the DNS server includes
  EDNS COOKIE options in responses (common with home routers). `wget` and
  `getent` are unaffected (they use musl's native resolver). This breaks the
  `buildAppsec-*-musl` task which needs to `git clone` cmake dependencies.
  Fix: pass `--dns 8.8.8.8` to the Docker command.
- `--info` is recommended for seeing test output in the console. Without it, output goes only to the HTML report in `build/reports/tests/`.
