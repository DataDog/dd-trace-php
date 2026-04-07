# System Tests

## CI Jobs

**Source:** `.gitlab/generate-package.php` -- the `.system_tests` template and
individual job definitions immediately below it. The template defines the base
image, `before_script` (Docker + Python setup, clone of `system-tests` repo,
artifact placement, `./build.sh`), and artifact collection. Each concrete job
extends this template and runs `./run.sh` with the appropriate scenario.

| CI Job | Scenario argument | What it does |
|--------|-------------------|--------------|
| `System Tests: [default]` | *(none -- default scenario)* | Core tracer + AppSec default scenario |
| `System Tests: [APPSEC_API_SECURITY]` | `APPSEC_API_SECURITY` | API Security with schema types |
| `System Tests: [APPSEC_API_SECURITY_RC]` | `APPSEC_API_SECURITY_RC` | API Security remote config |
| `System Tests: [APPSEC_API_SECURITY_NO_RESPONSE_BODY]` | `APPSEC_API_SECURITY_NO_RESPONSE_BODY` | API Security without response body |
| `System Tests: [INTEGRATIONS]` | `INTEGRATIONS` | Library integrations scenario |
| `System Tests: [CROSSED_TRACING_LIBRARIES]` | `CROSSED_TRACING_LIBRARIES` | Cross-library distributed tracing |
| `System Tests: [parametric]` | `PARAMETRIC` | Parametric tests (language-agnostic API conformance) |
| `System Tests: [tracer-release]` | *(dynamic)* | Tracer release scenarios; master/scheduled only, 4h timeout |

Runner: `docker-in-docker:amd64`
Image: `python:3.12-slim-bullseye` (the job itself installs Docker
inside the container)

The `System Tests` job (the matrix one) uses `parallel: matrix:` to
expand into five parallel jobs, one per `TESTSUITE` value. The
`[default]` and `[parametric]` jobs are separate definitions.

### Upstream dependencies (CI only)

All system-tests jobs `needs:` three upstream jobs:

1. `package extension: [amd64, x86_64-unknown-linux-gnu]` -- produces
   `packages/dd-library-php-*-x86_64-linux-gnu.tar.gz`
2. `datadog-setup.php` -- produces `packages/datadog-setup.php`
3. `prepare code` -- runs `composer update` + `make generate`

The `before_script` moves `datadog-setup.php` and the `.tar.gz` into
`system-tests/binaries/` before calling `./build.sh php`.

The `[parametric]` job overrides `BUILD_SH_ARGS` to `-i runner` instead
of the default `php`.

### Not covered here

Onboarding / SSI system tests (`configure_system_tests` job, which sets
`SYSTEM_TESTS_SCENARIOS_GROUPS` to onboarding/SSI groups) are documented
separately in `system-tests-onboarding.md`. Those jobs require AWS
credentials; the jobs listed above do not.

## What It Tests

The `system-tests` framework (https://github.com/DataDog/system-tests)
is a cross-language end-to-end test suite. It spins up Docker containers
(a "weblog" app built from the library under test, a mock Datadog Agent,
and various backend services) and runs pytest scenarios against them.

For PHP, `./build.sh php` builds a Docker image that installs the PHP
package (via `datadog-setup.php` + the tarball placed in `binaries/`)
into a weblog app. `./run.sh <SCENARIO>` then exercises that image.

## Reproducing Locally

### Prerequisites

- **Docker with buildx:** system-tests uses `docker buildx build`. If
  `docker buildx version` fails, install the plugin:
  ```bash
  mkdir -p ~/.docker/cli-plugins
  curl -sSL "https://github.com/docker/buildx/releases/latest/download/buildx-$(uname -s | tr A-Z a-z)-amd64" \
    -o ~/.docker/cli-plugins/docker-buildx
  chmod +x ~/.docker/cli-plugins/docker-buildx
  ```
- **Python 3.12:** Required by the system-tests runner. If installed
  via `uv`, `build.sh` may fail at "Build virtual env" because the
  `EXTERNALLY-MANAGED` marker blocks `ensurepip`. Workaround: create
  the venv manually before running `build.sh`:
  ```bash
  cd system-tests
  uv venv venv --python python3.12 --seed
  ```

### 1. Build the PHP package locally

The system tests need two artifacts in the `binaries/` directory of the
`system-tests` checkout:

- `datadog-setup.php`
- `dd-library-php-<version>-x86_64-linux-gnu.tar.gz`

Build them from the working tree. Before starting, ensure submodules
are initialised (see
[building-locally.md](building-locally.md#submodule-initialisation)).
The build has three parts: tracing extension, appsec components, and
tarball assembly. See
[building-locally.md](building-locally.md) for all build commands
and common gotchas (CARGO_HOME, devtoolset-7, `make` vs
`make static`, etc.).

`generate-final-artifact.sh` assembles the tarball from these
artifact directories. See
[building-locally.md](building-locally.md) for build commands and
gotchas (CARGO_HOME, devtoolset-7, `make` vs `make static`, etc.):

| Directory | Contents | Build reference |
|-----------|----------|----------------|
| `extensions_x86_64/` | `ddtrace-{API}[-zts\|-debug].so` | [Tracer (centos-7)](building-locally.md#for-system-tests-centos-7-release-like-build) |
| `appsec_x86_64/` | `ddappsec-{API}[-zts].so` | [Appsec extension](building-locally.md#for-release--system-tests-centos-7) |
| `appsec_x86_64/` | `libddappsec-helper-rust.so` | [Rust helper](building-locally.md#rust-helper) |
| `appsec_x86_64/` | `libddappsec-helper.so` | [C++ helper](building-locally.md#c-helper) |
| `appsec_x86_64/` | `recommended.json` | `cp appsec/recommended.json appsec_x86_64/` |
| `datadog-profiling/` | `datadog-profiling[-zts].so` | Can be fully stubbed (see section 1c) |

Each `php-{ver}_centos-7` image only ships one PHP version. In
practice you only need the version matching your weblog — **use the
[slim build](#1c-slim-build--one-php-version-only)** and stub the
rest with empty files. Section 1c has a complete copy-paste recipe
(build + stubs + tarball assembly) for PHP 8.2 NTS.

`generate-final-artifact.sh` does a hard `cp` for every PHP API ×
variant. Empty stub files satisfy this for versions you don't build.
Profiling can also be fully stubbed. See
[section 1c](#1c-slim-build--one-php-version-only) for all stub
commands.

See [building-locally.md § Appsec Helpers](building-locally.md#appsec-helpers)
for build commands for both helpers.

For tarball assembly and `datadog-setup.php`, see
[building-locally.md § Release Package Assembly](building-locally.md#release-package-assembly).
Output in `build/packages/`:
- `dd-library-php-<version>-x86_64-linux-gnu.tar.gz`
- `datadog-setup.php`

**Weblog PHP version:** the default weblog (`apache-mod-8.0`) may not be supported by
newer working-tree branches. Use `WEBLOG_VARIANT=apache-mod-8.2` if the default build
fails with "not supported". `apache-mod` variants stop at 8.2; for PHP 8.5
use `WEBLOG_VARIANT=php-fpm-8.5` (there is no 8.3 or 8.4 weblog):

```bash
WEBLOG_VARIANT=apache-mod-8.2 ./build.sh php
WEBLOG_VARIANT=apache-mod-8.2 ./run.sh

# PHP 8.4 / 8.5 — fpm only
WEBLOG_VARIANT=php-fpm-8.5 ./build.sh php
WEBLOG_VARIANT=php-fpm-8.5 ./run.sh
```

### 1c. Slim build — one PHP version only

If you only want to test a specific PHP version and variant, build just that one and stub
the rest with empty files. `generate-final-artifact.sh` requires a `.so` to exist for
every PHP API × variant combination; empty stubs satisfy that requirement without building
unused code.

**The weblog variant must match the PHP version you build** — `datadog-setup.php` installs
the ABI that matches the weblog's running PHP. If the matching `.so` is a zero-byte
stub, the extension will fail to load. You **must** set `WEBLOG_VARIANT` to match the
PHP version you actually compiled. Available weblogs: `apache-mod-7.0` through
`apache-mod-8.2`, and `php-fpm-7.0` through `php-fpm-8.2` plus `php-fpm-8.5`. ZTS
variants also exist (`apache-mod-7.0-zts` through `apache-mod-8.2-zts`). There is no
PHP 8.3 or 8.4 weblog.

Example for **PHP 8.2 NTS** with `apache-mod-8.2` (ABI `20220829`):

```bash
# Build only PHP 8.2 NTS tracing extension
.claude/ci/dockerh --cache st-compile-8.2-nts --overlayfs --php nts \
  datadog/dd-trace-ci:php-8.2_centos-7 \
  -- bash -c 'export CARGO_HOME=$PWD/tmp/cargo_home;
    make clean && make -j$(nproc)'

# Extract the .so from the overlay volume to the host
mkdir -p extensions_x86_64
.claude/ci/docker-upper-cp dd-ci-st-compile-8.2-nts \
  tmp/build_extension/modules/ddtrace.so \
  extensions_x86_64/ddtrace-20220829.so

# Stub ddtrace variants we didn't build
touch extensions_x86_64/ddtrace-20220829-zts.so
touch extensions_x86_64/ddtrace-20220829-debug.so
touch extensions_x86_64/ddtrace-20220829-debug-zts.so
for api in 20151012 20160303 20170718 20180731 20190902 20200930 20210902 20230831 20240924 20250925; do
  for suffix in "" -zts -debug -debug-zts; do
    touch "extensions_x86_64/ddtrace-${api}${suffix}.so"
  done
done

# Build appsec extension for 8.2; stub the rest
.claude/ci/dockerh --cache st-compile-appsec-8.2 --overlayfs --root \
  datadog/dd-trace-ci:php-8.2_centos-7 \
  -- bash -c 'source /opt/rh/devtoolset-7/enable;
    PHP_VERSION=8.2 .gitlab/build-appsec.sh'
.claude/ci/docker-upper-cp dd-ci-st-compile-appsec-8.2 \
  appsec_x86_64 appsec_x86_64

for api in 20151012 20160303 20170718 20180731 20190902 20200930 20210902 20230831 20240924 20250925; do
  for suffix in "" -zts; do
    touch "appsec_x86_64/ddappsec-${api}${suffix}.so"
  done
done

# Build appsec helpers (see building-locally.md § Appsec Helpers)
.claude/ci/dockerh --cache compile-appsec-helper-rust --overlayfs \
    datadog/dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash .gitlab/build-appsec-helper-rust.sh
.claude/ci/docker-upper-cp dd-ci-compile-appsec-helper-rust \
  appsec_x86_64/libddappsec-helper-rust.so \
  appsec_x86_64/libddappsec-helper-rust.so

# Build C++ helper (see building-locally.md § C++ helper)
.claude/ci/dockerh --cache compile-appsec-helper-cpp --overlayfs \
    registry.ddbuild.io/images/mirror/b1o7r7e0/nginx_musl_toolchain \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash .gitlab/build-appsec-helper.sh
.claude/ci/docker-upper-cp dd-ci-compile-appsec-helper-cpp \
  appsec_x86_64/libddappsec-helper.so \
  appsec_x86_64/libddappsec-helper.so
.claude/ci/docker-upper-cp dd-ci-compile-appsec-helper-cpp \
  appsec_x86_64/recommended.json \
  appsec_x86_64/recommended.json

# Build profiler for PHP 8.2 (see building-locally.md § Profiler)
.claude/ci/dockerh --cache compile-profiler-8.2-gnu --overlayfs \
    --root \
    datadog/dd-trace-ci:php-8.2_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.2 bash .gitlab/build-profiler.sh \
      datadog-profiling/x86_64-unknown-linux-gnu/lib/php/20220829 nts'
.claude/ci/docker-upper-cp dd-ci-compile-profiler-8.2-gnu \
  datadog-profiling datadog-profiling

# Stub profiling for other API versions
for api in 20160303 20170718 20180731 20190902 20200930 20210902 20230831 20240924 20250925; do
  mkdir -p "datadog-profiling/x86_64-unknown-linux-gnu/lib/php/${api}"
  touch "datadog-profiling/x86_64-unknown-linux-gnu/lib/php/${api}/datadog-profiling.so"
  touch "datadog-profiling/x86_64-unknown-linux-gnu/lib/php/${api}/datadog-profiling-zts.so"
done

# Assemble the tarball (see building-locally.md for details)
.claude/ci/dockerh --cache pkg-8.2 --overlayfs \
  datadog/dd-trace-ci:php_fpm_packaging -- -c '
set -e
TRIPLET=x86_64-unknown-linux-gnu \
  ./tooling/bin/generate-final-artifact.sh \
  $(<VERSION) "build/packages" "${PWD}"
'
.claude/ci/docker-upper-cp dd-ci-pkg-8.2 \
  build/packages build/packages

# Build datadog-setup.php (not produced by generate-final-artifact.sh)
.claude/ci/dockerh --cache pkg-8.2 --overlayfs \
  php:8.2-cli -- bash -c 'make build/packages/datadog-setup.php VERSION=$(cat VERSION)'
.claude/ci/docker-upper-cp dd-ci-pkg-8.2 \
  build/packages/datadog-setup.php build/packages/datadog-setup.php

WEBLOG_VARIANT=apache-mod-8.2 ./build.sh php
WEBLOG_VARIANT=apache-mod-8.2 ./run.sh
```

The stub files are only there to keep the packaging script from aborting.
`datadog-setup.php` installs only the ABI that matches the running PHP and ignores the rest.

### 1b. Alternative: place only the .so files you want to test

`install_ddtrace.sh` in system-tests supports `.so` overrides: when no
`dd-library-php-*.tar.gz` is present it downloads the **latest released package**
from GitHub as the base, then replaces installed files with any `.so` files it finds
in `binaries/`.

| File in `binaries/` | What it replaces |
|---------------------|-----------------|
| `ddtrace.so` | The installed `ddtrace.so` (searched under `/root`, `/opt`, `/usr/lib/php`) |
| `ddappsec.so` + `libddappsec-helper.so` | Both required together; replaces appsec extension and C++ helper |
| `libddappsec-helper-rust.so` | Placed alongside the C++ helper (enables `DD_APPSEC_HELPER_RUST_REDIRECTION`) |
| `libddwaf.so` | Placed alongside the C++ helper |

**Hard constraints — this approach only works if all three hold:**

1. **GLIBC compatibility.** The `.so` built by the normal `compile extension` CI job
   (bookworm image) requires GLIBC_2.34. The default weblog (`apache-mod-8.0`) runs on
   Debian Bullseye (GLIBC_2.31) — the extension loads but immediately crashes with
   `GLIBC_2.32 not found`. You must either build with a lower-glibc toolchain (the
   package pipeline's centos-7 image targets GLIBC_2.17) or use a weblog with a newer
   base OS.

2. **PHP ABI match.** The `.so` must be compiled for the same PHP version as the weblog.
   PHP ABIs are not cross-compatible. The default weblog uses PHP 8.0 (ABI `20200930`).

3. **Weblog install path.** `install_ddtrace.sh` searches `find /root /opt /usr/lib/php`
   for the installed extension. `apache-mod-*` weblogs (Debian-based) install under
   `/root/php/...`; `php-fpm-*` weblogs (Ubuntu + `ondrej/php` PPA) install under
   `/usr/lib/php/<ABI>/`. Both are covered.

**Summary:** in practice this approach is harder than it looks. The full-package path
(section 1) is more reliable. The `.so` override is most useful when you already have a
package-pipeline–built artifact (centos-7 compiled, GLIBC_2.17) and want to swap one
component without reassembling the full tarball.

**Caveats:**
- The base package comes from the GitHub **latest release**. Files it provides
  (`recommended.json`, `.ini` config, other extensions) reflect that released version.
- `compile_extension.sh` requires CI env vars (`CI_COMMIT_SHA`, `CI_COMMIT_BRANCH`).
  Workaround: pass `-e CI_COMMIT_TAG=local-build` to the docker run so
  `append-build-id.sh` exits early.

### 2. Clone system-tests and place artifacts

```bash
git clone https://github.com/DataDog/system-tests.git
mkdir -p system-tests/binaries

cp build/packages/datadog-setup.php system-tests/binaries/
cp build/packages/dd-library-php-*-x86_64-linux-gnu.tar.gz system-tests/binaries/
```

### 3. Build the weblog image

```bash
cd system-tests

# For all jobs except [parametric]:
./build.sh php

# For [parametric]:
TEST_LIBRARY=php ./build.sh -i runner
```

### 4. Run scenarios

```bash
# Default scenario (System Tests: [default])
./run.sh

# A specific scenario (System Tests: [APPSEC_API_SECURITY], etc.)
./run.sh APPSEC_API_SECURITY

# Parametric tests
TEST_LIBRARY=php ./run.sh PARAMETRIC
```

### Running a single test

```bash
# By test file
./run.sh DEFAULT tests/path_to_test.py

# By test class
./run.sh DEFAULT tests/appsec/waf/test_addresses.py::Test_BodyJson

# By test method
./run.sh DEFAULT tests/appsec/waf/test_addresses.py::Test_BodyJson::test_body_json

# By pattern
./run.sh DEFAULT -k "test_pattern"
```

### Inspecting logs after a run

Logs are written to `logs_<scenario>/` (or `logs/` for default) and
`logs_parametric/` under the `system-tests/` directory. Inside:

- `docker/weblog/logs/` -- PHP/weblog application logs
- `docker/weblog/logs/helper.log` -- AppSec helper logs (see
  `appsec/helper-rust/CLAUDE.md` for how to distinguish C++ vs Rust
  helper output)
- `interfaces/` -- captured agent traffic

## Using a custom helper-rust binary

To test with a locally-built Rust AppSec helper instead of the one
bundled in the package:

```bash
# Build the helper via Gradle
cd appsec/tests/integration
./gradlew buildHelperRust --info

# Extract from Docker volume
docker run -i --rm -v php-helper-rust:/vol alpine \
  cat /vol/libddappsec-helper.so > /path/to/system-tests/binaries/libddappsec-helper.so

# Then rebuild the weblog and run as usual
cd /path/to/system-tests
./build.sh php
./run.sh APPSEC_API_SECURITY
```

## Gotchas

- The CI job installs Docker (docker-ce, containerd, buildx) inside the
  `python:3.12-slim-bullseye` container at runtime. Locally you just
  need Docker already running on the host.

- **Build timeout.** `build.sh` has a 10-minute timeout per attempt
  (`SYSTEM_TEST_BUILD_TIMEOUT=600`). Cold builds with no Docker layer
  cache can exceed this. Override with
  `SYSTEM_TEST_BUILD_TIMEOUT=1200 ./build.sh php`.

- **Log directories become root-owned.** After runs, `logs*/` directories
  are owned by root. Remove them via Docker before re-running:
  `docker run --rm -v $(pwd):/s alpine rm -rf /s/logs /s/logs_*`

- **Run the orchestrator on the host, not in a container.** Volume mount
  paths in `run.sh` resolve on the Docker host. If you run the orchestrator
  inside a container with the Docker socket mounted, the paths won't match
  and non-parametric scenarios will fail.

- `system-tests` is cloned fresh from `main` on every CI run -- there is
  no pinned commit or tag. A breaking change upstream can cause failures
  unrelated to dd-trace-php changes.

- The `.tar.gz` filename includes the library version (from the
  `VERSION` file). If you build the package locally, make sure the
  version in the filename matches what `datadog-setup.php` expects.

- The `[parametric]` job uses `BUILD_SH_ARGS="-i runner"` instead of
  `php`. This builds a different Docker image (the parametric test
  runner) rather than the PHP weblog.

- Non-parametric scenarios (DEFAULT, APPSEC_API_SECURITY, INTEGRATIONS,
  etc.) always run sequentially — `run.sh` hardcodes `pytest_numprocesses=1`
  for these. Only PARAMETRIC uses parallel workers (`-n auto`). CI sets
  `PYTEST_XDIST_AUTO_NUM_WORKERS=8` but this only affects PARAMETRIC.

- On Apple Silicon, `build.sh` targets `linux/arm64/v8` by default.
  When using a locally-built x86_64 tarball, you **must** export
  `DOCKER_DEFAULT_PLATFORM=linux/amd64` before running
  `./build.sh php`, or `datadog-setup.php` will fail to match the
  architecture in the filename. CI always sets this variable.
  Alternatively, build an arm64 `.so` with `make` (not `make static`)
  and use the `.so` override path — see
  [../debugging-system-tests.md](../debugging-system-tests.md).

- Artifacts are collected from `system-tests/logs_parametric/` and
  `system-tests/logs/` -- these directories are always uploaded
  regardless of job success or failure (`when: always`).

- **AppSec events silently missing when appsec artifacts are absent.** When
  `libddappsec-helper.so`, `ddappsec.so`, or `recommended.json` do not reach
  the weblog Docker image, the sidecar's `maybe_start_appsec()` skips WAF
  loading without any visible error. All AppSec-related scenarios (`default`,
  `APPSEC_API_SECURITY*`) will report "No appsec event validates this
  condition" or "No telemetry data to validate on". This also happens when
  building packages locally without the appsec components (see Phase 1b).
  After any change to `generate-package.php` or the tarball assembly that
  touches helper packaging, confirm that
  `libddappsec-helper.so` is present inside the assembled `.tar.gz`
  before running system tests.

- **Sidecar log level must be `debug` to see startup confirmation.**
  The default log level filters out the "Starting sidecar" message. When
  diagnosing sidecar startup issues in the weblog container, set
  `_DD_DEBUG_SIDECAR_LOG_METHOD=file:///tmp/sidecar.log` and
  `_DD_DEBUG_SIDECAR_LOG_LEVEL=debug`. Logs appear under
  `logs/docker/weblog/logs/sidecar.log` after the run.
