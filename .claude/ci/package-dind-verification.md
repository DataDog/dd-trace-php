# Docker-in-Docker Package Verification

These jobs verify that the packaged dd-trace-php extension works correctly
inside real-world framework containers and randomized PHP environments. They
run in the `verify` stage of the **package-trigger** child pipeline and require
Docker-in-Docker runners because they spin up containers internally.

## CI Jobs

**Source:**
- `.gitlab/generate-package.php` -- defines `framework test`,
  `installer tests`, and `randomized tests` jobs
- `dockerfiles/frameworks/Makefile` -- Makefile invoked by
  `framework test`
- `dockerfiles/frameworks/*.yml` -- docker-compose files for
  each framework test suite
- `tests/randomized/` -- randomized test infrastructure
- `dockerfiles/verify_packages/` -- `test_installer` target

| CI Job | Image | What it does |
|--------|-------|--------------|
| `framework test: [{suite}]` | `docker:29.4.0-noble` | Spins up a framework-specific Docker Compose stack and runs the framework's own test suite with ddtrace installed |
| `installer tests` | `docker:29.4.0-noble` | Runs `make -C dockerfiles/verify_packages test_installer`; verifies `datadog-setup.php` installer works on both amd64 and arm64 packages |
| `randomized tests: [amd64, {no-asan,asan}, {1..5}]` | `docker:29.4.0-noble` | Generates random PHP scenarios and runs them with ddtrace for 1m30s each; checks for crashes and unexpected behavior |

Runner: `docker-in-docker:amd64`
Matrix (`framework test`): `{flow, flow_no_ddtrace, mongodb-driver, mongodb-driver_no_ddtrace, phpredis3, phpredis3_no_ddtrace, phpredis4, phpredis4_no_ddtrace, phpredis5, phpredis5_no_ddtrace, wordpress, wordpress_no_ddtrace}`
Matrix (`randomized tests`): `{no-asan, asan}` x `{1, 2, 3, 4, 5}` (arm64 variants exist but are commented out pending `docker-in-docker:arm64` runner availability)

## What It Tests

### framework test

Each suite name maps to a docker-compose YAML in `dockerfiles/frameworks/`. The
Makefile:
1. Copies the built `.deb` package into
   `dockerfiles/frameworks/nginx_file_server/ddtrace.deb`
2. Starts a docker-compose stack with the framework app + ddtrace installed
3. Runs the framework's own test suite against the app

The `_no_ddtrace` variants run without ddtrace loaded, serving as a baseline to
confirm the framework itself is not broken.

**Upstream artifacts needed:**
- `package extension: [amd64, x86_64-unknown-linux-gnu]`

### installer tests

Verifies that `datadog-setup.php` can correctly install the extension from the
built packages. Tests both amd64 and arm64 packages.

**Upstream artifacts needed:**
- `package extension: [amd64, x86_64-unknown-linux-gnu]`
- `package extension: [arm64, aarch64-unknown-linux-gnu]`
- `datadog-setup.php`

### randomized tests

Generates 4 random PHP application scenarios per run, each exercising different
combinations of PHP versions, SAPIs (cli, fpm, apache), and extensions. Each
scenario runs for 1m30s with 2 concurrent jobs. The `analyze` step
post-processes results.

The no-asan variant uses the regular glibc package; the asan variant uses the
ASAN-instrumented package to catch memory errors.

**Upstream artifacts needed:**
- `package extension: [amd64, x86_64-unknown-linux-gnu]` (no-asan) or `package
  extension asan` (asan variant)

## Reproducing Locally

All DinD verification jobs need packaged artifacts from upstream
compile/package jobs. Two ways to obtain them:

- **From CI:** use `tooling/bin/download-artifacts` (e.g., `--preset
  extension-amd64-gnu`, `--preset extension-asan`, `--preset datadog-setup`).
  See "Downloading artifacts" in [index.md](index.md).
- **Build locally:** see the ".deb from source" section below.

### Building a .deb from source

The framework tests need a `.deb` containing `.so` variants for the PHP
version(s) used by the test containers. The wordpress test uses PHP 7.0 (API
20151012).

`build-tracing.sh` produces both `.a` archives and standalone `.so` files. For
local builds, the standalone `.so` files can be used directly — no separate
sidecar build or link step needed. See also
[compile-artifacts.md](compile-artifacts.md) and
[packaging-oci.md](packaging-oci.md).

**Step 1 — Compile tracing extension for PHP 7.0 (~49s):**

```bash
cd ~/repos/dd-trace-php
git submodule update --init libdatadog

.claude/ci/dockerh --cache compile-tracing-7.0-gnu \
    --overlayfs --root \
    datadog/dd-trace-ci:php-7.0_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=local-build \
    -- bash -c \
    'PHP_VERSION=7.0 bash .gitlab/build-tracing.sh'
```

Produces `.a` archives in `extensions_x86_64/` and standalone `.so` files in
`standalone_x86_64/`, both in overlayfs volume `dd-ci-compile-tracing-7.0-gnu`.
Repeat with different `PHP_VERSION` and `--cache` for other versions.

**Step 2 — Package into .deb (~5s):**

```bash
.claude/ci/dockerh --cache package-deb \
    --clean-cache --overlayfs --root \
    datadog/dd-trace-ci:php-8.1_centos-7 \
    -v dd-ci-compile-tracing-7.0-gnu:/cache-tracing:ro \
    -- bash -c '
      cp /cache-tracing/upper/standalone_x86_64/*.so \
         extensions_x86_64/
      make .deb.x86_64
    '
```

Extract the `.deb`:

```bash
mkdir -p build/packages
docker run --rm \
    -v dd-ci-package-deb:/cache:ro \
    -v $(pwd)/build/packages:/out \
    alpine sh -c \
    'cp /cache/upper/build/packages/*.deb /out/'
```

**Step 3 — Run the framework test:**

```bash
rm -f \
  dockerfiles/frameworks/nginx_file_server/ddtrace.deb
CI=true make -f dockerfiles/frameworks/Makefile wordpress
```

### framework test

The `dockerfiles/frameworks/Makefile` has two modes:
- **Without `CI`:** auto-downloads a `.deb` from GitHub Releases (~8 MB).
  Simplest for quick smoke tests.
- **With `CI=true`:** uses the `.deb` from `build/packages/`. Required to test
  your own build.

```bash
# Quick smoke test (downloads .deb from GitHub Releases):
make -f dockerfiles/frameworks/Makefile wordpress

# Test with your own build:
tooling/bin/download-artifacts --preset extension-amd64-gnu
mkdir -p build/packages
cp packages/datadog-php-tracer_*.deb build/packages/
CI=true make -f dockerfiles/frameworks/Makefile wordpress

# No-ddtrace baseline:
make -f dockerfiles/frameworks/Makefile wordpress_no_ddtrace
```

### randomized tests

Valid platform names are `centos7` and `buster` (defined in
`tests/randomized/config/platforms.php`), **not** `debian`. Only the no-asan +
centos7 combination currently works. See the `after_script` gotcha below for
why other combinations fail.

The base services (Elasticsearch, etc.) must be started before the test
scenarios.

```bash
# Place the package .tar.gz at the repo root
cp packages/dd-library-php-*-x86_64-linux-gnu.tar.gz .

# Start base services first
docker-compose \
  -f tests/randomized/lib/docker-compose.yml up -d

# Generate and run (no-asan with centos7)
make -C tests/randomized library.local
make -C tests/randomized generate \
  PLATFORMS=centos7 NUMBER_OF_SCENARIOS=2
make -C tests/randomized test \
  CONCURRENT_JOBS=2 DURATION=1m30s

# Fix result file permissions (containers create as root)
docker run --rm \
  -v $(pwd)/tests/randomized/.tmp.scenarios/.results:/r \
  alpine chmod -R a+r /r
make -C tests/randomized analyze

# Clean up
make -C tests/randomized clean
docker-compose \
  -f tests/randomized/lib/docker-compose.yml down
```

### installer tests

The installer tests (`make -C dockerfiles/verify_packages test_installer`) run
~39 test scripts. Each spins up a Docker container, runs
`php ./build/packages/datadog-setup.php --php-bin php`, and verifies the
installed extension version matches `cat VERSION`.

`datadog-setup.php` downloads tarballs from:
`{DD_TEST_INSTALLER_REPO}/releases/download/{RELEASE_VERSION}/dd-library-php-{RELEASE_VERSION}-{arch}-linux-{libc}.tar.gz`

where `DD_TEST_INSTALLER_REPO` comes from `dockerfiles/verify_packages/.env`
and `RELEASE_VERSION` is baked into `datadog-setup.php` at build time (replaces
`@release_version@`).

In CI, `generate-installers.sh` detects the `+` in the version and rewrites
the URL to point to S3. Running locally requires either waiting for the
`publish to public s3` CI job, or serving tarballs from a local HTTP server
(described below).

#### Running with a local HTTP server (no S3 dependency)

This approach works with both CI-downloaded and locally-built artifacts.
The key pieces that must all agree:

- `VERSION` file must match the version baked into the compiled `.so` files
- `RELEASE_VERSION` in `build/packages/datadog-setup.php` must equal `VERSION`
- Tarball filenames must contain that version string
- `DD_TEST_INSTALLER_REPO` in `.env` must point to the HTTP server

##### Step 1: Obtain the tarballs

**Option A -- From CI artifacts:**

```bash
tooling/bin/download-artifacts --preset extension-amd64-gnu \
  -o /tmp/ci-artifacts-gnu
tooling/bin/download-artifacts --preset extension-amd64-musl \
  -o /tmp/ci-artifacts-musl
```

The combined tarballs are the large files (~900MB gnu, ~700MB musl) whose
names do NOT contain a PHP API number.

**Option B -- From local builds:**

After running the full compile pipeline (see
[compile-artifacts.md](compile-artifacts.md)), generate tarballs:

```bash
TRIPLET=x86_64-unknown-linux-gnu \
  bash tooling/bin/generate-final-artifact.sh "$(cat VERSION)" build/packages .
TRIPLET=x86_64-alpine-linux-musl \
  bash tooling/bin/generate-final-artifact.sh "$(cat VERSION)" build/packages .
```

The script needs compiled extensions in `extensions_x86_64/`,
`datadog-profiling/`, `appsec_x86_64/`, and `src/`. If built via
`dockerh --overlayfs`, extract from volumes first (see
[compile-artifacts.md](compile-artifacts.md)).

##### Step 2: Determine the version

```bash
# From CI tarball filenames:
VERSION_STR=$(ls /tmp/ci-artifacts-gnu/dd-library-php-*-x86_64-linux-gnu.tar.gz \
  | sed 's|.*/dd-library-php-\(.*\)-x86_64-linux-gnu.tar.gz|\1|')

# Or from locally-built packages:
VERSION_STR=$(cat VERSION)

echo "Version: $VERSION_STR"
```

##### Step 3: Update VERSION and .env

```bash
echo -n "$VERSION_STR" > VERSION

# Docker bridge gateway IP (reachable from containers):
GATEWAY=$(docker network inspect bridge \
  --format '{{(index .IPAM.Config 0).Gateway}}')
echo "DD_TEST_INSTALLER_REPO=http://${GATEWAY}:8888" \
  > dockerfiles/verify_packages/.env
```

On Docker Desktop (macOS/Windows), use
`host.docker.internal` instead of `$GATEWAY`.

##### Step 4: Build datadog-setup.php (non-CI path)

The non-CI codepath preserves `DD_TEST_INSTALLER_REPO`
support (the CI path in `generate-installers.sh` hardcodes
S3 URLs):

```bash
mkdir -p build/packages
sed "s|@release_version@|${VERSION_STR}|g" \
  ./datadog-setup.php > build/packages/datadog-setup.php
```

This step must come **after** writing `VERSION` (step 3).
The Makefile rule `build/packages/datadog-setup.php: VERSION`
re-runs `generate-installers.sh` whenever `VERSION` is newer
than `datadog-setup.php`. By running `sed` after the
`VERSION` write, the output file is naturally newer and Make
will not overwrite it. If `CI_JOB_ID` is set in the
environment, `generate-installers.sh` takes the CI branch
and hardcodes S3 URLs, breaking the local server setup.

##### Step 5: Set up directory structure and HTTP server

```bash
mkdir -p "/tmp/fake-repo/releases/download/${VERSION_STR}/"

# Copy combined tarballs (adjust source paths):
cp /tmp/ci-artifacts-gnu/dd-library-php-*-x86_64-linux-gnu.tar.gz \
  "/tmp/fake-repo/releases/download/${VERSION_STR}/"
cp /tmp/ci-artifacts-musl/dd-library-php-*-x86_64-linux-musl.tar.gz \
  "/tmp/fake-repo/releases/download/${VERSION_STR}/"

# Also copy to build/packages/ for tests that use --file:
cp "/tmp/fake-repo/releases/download/${VERSION_STR}/dd-library-php-${VERSION_STR}-x86_64-linux-gnu.tar.gz" \
  build/packages/

# Start the server (proxies misses to GitHub for old
# versions needed by upgrade tests):
.claude/ci/serve-installer-packages /tmp/fake-repo &
```

##### Step 6: Run the tests

```bash
# All tests:
make -C dockerfiles/verify_packages test_installer

# Single test:
make -C dockerfiles/verify_packages test_first_install.sh
```

##### Step 7: Clean up

```bash
git checkout VERSION dockerfiles/verify_packages/.env
kill %1  # stop HTTP server
```

#### How it works

- `datadog-setup.php` first tries a per-PHP-API tarball (e.g.,
  `dd-library-php-{ver}-x86_64-linux-gnu-20190902.tar.gz`) which returns 404
  (only the combined tarball is served). It falls back to the combined tarball.
- Tests that install old versions download their `datadog-setup.php` from
  GitHub, but those old scripts also read `DD_TEST_INSTALLER_REPO`. The proxy
  forwards their requests to GitHub (`urllib` follows redirects).
- The `+` character in the version is safe in URL paths; Python's http.server
  and PHP's curl handle it correctly.
- Building `datadog-setup.php` with plain `sed` instead of
  `generate-installers.sh` avoids the CI codepath that rewrites URLs to S3.

## Gotchas

- The `framework test` job installs `docker-compose` v2.36.0 as a standalone
  binary (`/usr/local/bin/docker-compose`), not the Docker Compose plugin. The
  Makefile invokes `docker-compose` (hyphenated), not `docker compose`.

- `randomized tests` arm64 variants are **commented out** in the generator,
  waiting for a `docker-in-docker:arm64` runner.

- Each `randomized tests` index (1--5) runs an independent set of 4 randomly
  generated scenarios. The 5 parallel instances give coverage breadth; there is
  no deduplication.

- The `_no_ddtrace` framework test variants exist to detect false positives: if
  `wordpress_no_ddtrace` also fails, the problem is in the test environment,
  not in ddtrace.

- `installer tests` needs packages from **both** architectures (amd64 + arm64)
  even on an amd64 runner, because `datadog-setup.php` is tested for its
  ability to select the correct package.

- **`installer tests` VERSION mismatch.** The installer compares the installed
  extension version against `VERSION`. CI runs `append-build-id.sh` which bumps
  it (e.g. `1.17.0` -> `1.18.0+<sha>`). Without this step, tests fail with
  "Wrong ddtrace version".

- **`installer tests` downloads from S3 by default.** In CI,
  `datadog-setup.php` fetches archives from S3. To run locally without waiting
  for `publish to public s3`, use the local HTTP server approach described
  above.

- **Randomized test platform names are `centos7` and `buster`** (defined in
  `tests/randomized/config/platforms.php`), not `debian`. Only the no-asan +
  centos7 combination actually works (see the `after_script` gotcha below).

- **Randomized test result files are root-owned.** Docker containers create
  result files as `root:root` mode 600. Without root access, `make analyze`
  fails with "Permission denied". Fix with: `docker run --rm -v
  $(pwd)/tests/randomized/.tmp.scenarios/.results:/r alpine chmod -R a+r /r`

- **Elasticsearch 7.17.4 crashes on modern kernels.** On hosts with cgroupv2,
  the ES container in `tests/randomized/lib/docker-compose.yml` crashes.
  Updating to `elasticsearch:7.17.28` fixes it.

- **Framework test mysql containers persist.** After running `wordpress`, the
  mysql:5.7 container stays running. To fully clean up: `docker-compose -f
  dockerfiles/frameworks/nginx_file_server.yml -f
  dockerfiles/frameworks/wordpress.yml down`

- **Randomized tests `analyze` runs in `after_script` -- failures are silently
  ignored.** The `make ... analyze` step in `.gitlab/generate-package.php`
  (line 806) runs in `after_script`, which GitLab treats as non-fatal. When
  `analyze` exits with code 1, GitLab logs `WARNING: after_script failed` and
  marks the job as succeeded. The result is that **randomized tests provide
  zero coverage on buster** and **zero ASAN coverage on any platform**. Only
  the no-asan + centos7 scenarios actually execute the extension, and even
  those failures are masked. Root causes:
  - **ASAN + buster:** the ASAN `.so` is built on bookworm (glibc 2.36) but
    buster has glibc 2.28 -- `GLIBC_2.29 not found` at load time.
  - **ASAN + centos7:** centos7 images only have NTS PHP, but the ASAN package
    only contains `debug-zts` variants.
  - **No-asan + buster:** buster images have `debug-zts` PHP, but the no-asan
    package does not include debug-zts variants. To fix: move `analyze` from
    `after_script` to `script`, and either create bookworm-based randomized
    test images or restrict `RANDOMIZED_RESTRICT_PLATFORMS` per variant.
