# Native Package Verification

These jobs verify that the packaged dd-trace-php extension installs and runs correctly
on real distribution base images (Alpine, CentOS, Debian, Ubuntu, Windows) and in
specialized configurations (PECL, minimal installs, SSI loader, profiling). They run
in the `verify` stage of the **package-trigger** child pipeline and use native runners
(no Docker-in-Docker), though some use GitLab service containers.

## CI Jobs

**Source:**
- `.gitlab/generate-package.php` -- defines all jobs listed below
- `dockerfiles/verify_packages/verify.sh` -- main verification script for distro jobs
- `dockerfiles/verify_packages/{alpine,centos,debian}/install.sh` -- per-distro install helpers
- `dockerfiles/verify_packages/verify_tar_gz_root.sh` -- tar.gz ownership verification
- `dockerfiles/verify_packages/verify_no_ext_json.sh` -- JSON-less PHP verification
- `dockerfiles/verify_packages/verify_windows.ps1` -- Windows verification
- `loader/bin/test.sh` -- loader test runner

| CI Job | Image | What it does |
|--------|-------|--------------|
| `verify alpine: [{packages}, {image}, {install_type}]` | `alpine:{ver}` or `php:{ver}-fpm-alpine` | Installs ddtrace on Alpine via php_installer or native_package; verifies CLI + FPM produce traces |
| `verify centos: [{php_ver}, {install_type}]` | `centos:7` | Installs ddtrace on CentOS 7 with remi PHP packages; verifies CLI + Apache produce traces |
| `verify debian: [{php_ver}, {install_type}, {image}]` | `debian:{bullseye,bookworm}-slim` | Installs ddtrace on Debian with sury PHP packages; verifies CLI + FPM + Apache produce traces |
| `verify .tar.gz: [{arch}]` | `debian:bullseye-slim` | Extracts `.tar.gz` package, verifies file ownership is root, runs `post-install.sh`, checks `php --ri=ddtrace` |
| `verify no json ext` | `alpine:3.12` | Installs ddtrace on Alpine without the JSON PHP extension; verifies it still loads and works |
| `verify windows` | Windows runner (no container image) | See [windows-tests.md](windows-tests.md) |
| `Loader test on {arch} libc: [{ver}, {flavour}]` | `dd-trace-ci:php-{ver}_{suffix}` | Extracts SSI loader package, runs `loader/bin/test.sh` phpt tests; optionally runs `check_glibc_version.sh` |
| `Loader test on {arch} alpine` | `alpine:3.20` | Installs PHP 8.3 from apk, extracts SSI loader musl package, runs `loader/bin/test.sh` |
| `min install tests` | `dd-trace-ci:php-8.0-shared-ext` | Installs `.deb` package via `dpkg`, runs `make run_tests` + `make test_c` against the installed extension |
| `pecl tests: [{ver}]` | `dd-trace-ci:php-{ver}_bookworm-6` | Installs ddtrace from PECL `.tgz`, runs `pecl run-tests` against the installed extension |
| `test early PHP 8.1` | `ubuntu:jammy` | Installs stock Ubuntu 22.04 PHP 8.1 (no sury), installs ddtrace via `datadog-setup.php`, runs `pecl run-tests` |
| `x-profiling phpt tests on Alpine: [{ver}]` | `dd-trace-ci:php-compile-extension-alpine-{ver}` | Installs full package on Alpine via `datadog-setup.php --enable-profiling`, runs profiling phpt tests |

### Runners and matrices

**verify alpine:**
Runner: `arch:amd64`
Matrix: (Alpine 3.8--3.20 + latest with `php7`/`php` packages) + (`php:{ver}-fpm-alpine` for PHP 7.0--8.5)
Install types: `php_installer` (uses `datadog-setup.php`) and `native_package` (uses `.apk`)

**verify centos:**
Runner: `arch:amd64`
Matrix: PHP 7.0--8.3 x {php_installer, native_package}

**verify debian:**
Runner: `arch:amd64`
Matrix: PHP 7.0--8.5 x {php_installer, native_package} x {bullseye-slim, bookworm-slim}

**verify .tar.gz:**
Runner: `arch:{amd64,arm64}` (amd64 tests PHP 7.0 package, arm64 tests PHP 8.1 package)

**verify no json ext:**
Runner: `arch:amd64`

**verify windows:** See [windows-tests.md](windows-tests.md).

**Loader test on {arch} libc:**
Runner: `arch:{amd64,arm64}`
Matrix (amd64): PHP 5.6 (buster) + 7.0--7.3 (nts) + 7.4--8.5 (nts + zts, with valgrind)
Matrix (arm64): PHP 7.0--7.3 (nts) + 7.4--8.5 (nts + zts)

**Loader test on {arch} alpine:**
Runner: `arch:{amd64,arm64}`

**min install tests:**
Runner: `arch:amd64`

**pecl tests:**
Runner: `arch:amd64`
Matrix: PHP 7.0--8.5

**test early PHP 8.1:**
Runner: `arch:amd64`

**x-profiling phpt tests on Alpine:**
Runner: `arch:amd64`
Matrix: PHP 7.1--8.5

## What It Tests

### verify {alpine,centos,debian}

These are the primary distribution smoke tests. They:

1. Install PHP from the distro's package manager (apk/yum/apt with sury)
2. Install ddtrace using either `datadog-setup.php` (`php_installer` type) or
   the native package (`.apk`/`.rpm`/`.deb` via `native_package` type)
3. Run `dockerfiles/verify_packages/verify.sh` which:
   - Starts a CLI PHP script and checks it produces traces (sent to
     `request-replayer`)
   - Starts Apache or FPM (depending on distro) and checks HTTP requests
     produce traces
   - Verifies `phpinfo()` shows ddtrace loaded

The `request-replayer` GitLab service container acts as a mock trace agent,
recording submitted traces for verification.

### verify .tar.gz

Extracts the `.tar.gz` package to `/` and verifies:
- `/opt` and `/opt/datadog-php` are owned by root (not the build user)
- `post-install.sh` runs successfully
- `php --ri=ddtrace` shows the extension info

### Loader tests

Test the SSI (Single Step Instrumentation) library loader, which is a minimal
PHP extension that loads the full ddtrace extension at runtime. The loader
package is extracted and `loader/bin/test.sh` runs the loader's own phpt test
suite. The glibc version check (`check_glibc_version.sh`) verifies the loader
binary does not require a newer glibc than the target platform provides.

### pecl tests

Verifies the PECL distribution path: installs from the `.tgz` built by `pecl
build`, enables the extension, and runs `pecl run-tests` which executes the
phpt test suite from the installed PECL package.

### test early PHP 8.1

Specifically tests against Ubuntu 22.04's stock PHP 8.1 (without the sury PPA),
which is an older patchlevel than what the CI images ship. This catches
compatibility issues with early 8.1 builds (e.g., missing symbols, changed
APIs).

### x-profiling phpt tests on Alpine

Installs the full package with `--enable-profiling` on Alpine and runs the
profiling extension's phpt test suite. This verifies that the profiler works
correctly on musl libc.

## Upstream Artifacts

All jobs need artifacts from packaging jobs (Group I / Group D):

| Job | Needs |
|-----|-------|
| `verify alpine` | `package extension: [amd64, x86_64-alpine-linux-musl]` + `datadog-setup.php` |
| `verify centos` | `package extension: [amd64, x86_64-unknown-linux-gnu]` + `datadog-setup.php` |
| `verify debian` | `package extension: [amd64, x86_64-unknown-linux-gnu]` + `datadog-setup.php` |
| `verify .tar.gz: [amd64]` | `package extension: [amd64, x86_64-unknown-linux-gnu]` + `datadog-setup.php` |
| `verify .tar.gz: [arm64]` | `package extension: [arm64, aarch64-unknown-linux-gnu]` + `datadog-setup.php` |
| `verify no json ext` | `package extension: [amd64, x86_64-alpine-linux-musl]` |
| `verify windows` | `package extension windows` + `datadog-setup.php` |
| `Loader test on {arch} libc` | `package loader: [{arch}]` |
| `Loader test on {arch} alpine` | `package loader: [{arch}]` |
| `min install tests` | `package extension: [amd64, x86_64-unknown-linux-gnu]` |
| `pecl tests` | `pecl build` |
| `test early PHP 8.1` | `package extension: [amd64, x86_64-unknown-linux-gnu]` + `datadog-setup.php` |
| `x-profiling phpt tests on Alpine` | `package extension: [amd64, x86_64-alpine-linux-musl]` + `datadog-setup.php` |

## Reproducing Locally

Most of these jobs are difficult to reproduce locally because they require packaged
artifacts from upstream compile/package jobs. Two ways to obtain them:

- **From CI:** use `.claude/ci/download-artifacts` to download preset packages
  (e.g., `--preset extension-amd64-gnu`, `--preset ssi-amd64`, `--preset datadog-setup`).
  See the "Downloading artifacts" section in [index.md](index.md) for full usage.
- **Build locally:** follow [compile-artifacts.md](compile-artifacts.md) to compile
  the extension and packaging artifacts from source.

Once you have the artifacts, place them in the expected directory structure and
run the verification script inside the appropriate container via `dockerh`.

All examples below use `dockerh --overlayfs`.  Jobs that install packages or
write to system directories (verify distro, verify .tar.gz, verify no json ext)
need **`--root`** so the container stays as root.  Jobs that only run tests as a
regular user (loader tests, pecl tests) omit `--root`.

### verify {alpine,centos,debian}

These scripts run `apt`/`apk`/`yum install` and start services → use `--root`.
The CI `before_script` does `mkdir build; mv packages build` then installs
`curl` (and `INSTALL_PACKAGES` on Alpine).  The `script` is just
`./dockerfiles/verify_packages/verify.sh`.  `datadog-setup.php` is used from
the repo checkout (CWD), not from the packages directory.

```bash
# Example: Debian bookworm, PHP 8.3, php_installer.
# Start request-replayer first (needed for trace verification):
docker network create verify-net 2>/dev/null || true
docker rm -f replayer 2>/dev/null || true
docker run -d --name replayer --network verify-net \
  --network-alias request-replayer \
  datadog/dd-trace-ci:php-request-replayer-2.0

.claude/ci/dockerh --cache verify-debian-83 --overlayfs --root \
  debian:bookworm-slim \
  -v /path/to/packages:/artifacts:ro \
  --network verify-net \
  -e DD_AGENT_HOST=request-replayer -e DD_TRACE_AGENT_PORT=80 \
  -e DD_TRACE_AGENT_FLUSH_INTERVAL=1000 \
  -e PHP_VERSION=8.3 -e INSTALL_MODE=sury -e INSTALL_TYPE=php_installer \
  -- bash -c '
    mkdir -p build/packages
    cp /artifacts/dd-library-php-*-x86_64-linux-gnu.tar.gz build/packages/
    apt update && apt-get install -y curl
    ./dockerfiles/verify_packages/verify.sh
  '

# Cleanup
docker rm -f replayer; docker network rm verify-net
```

For Alpine, replace the image and adjust the before_script to match CI:

```bash
.claude/ci/dockerh --cache verify-alpine --overlayfs --root \
  alpine:3.20 \
  -v /path/to/packages:/artifacts:ro \
  --network verify-net \
  -e DD_AGENT_HOST=request-replayer -e DD_TRACE_AGENT_PORT=80 \
  -e DD_TRACE_AGENT_FLUSH_INTERVAL=1000 \
  -e VERIFY_APACHE=no -e INSTALL_TYPE=php_installer \
  -- sh -c '
    mkdir -p build/packages
    cp /artifacts/dd-library-php-*-x86_64-linux-musl.tar.gz build/packages/
    cp /artifacts/*.apk build/packages/
    apk add --no-cache ca-certificates curl php php-fpm php-json
    ./dockerfiles/verify_packages/verify.sh
  '
```

Without request-replayer, for a basic "does it load" check, just verify
`php --ri=ddtrace` works after installation.

### verify .tar.gz

The CI `before_script` is just `mkdir build; mv packages build`.
The `script` runs `./dockerfiles/verify_packages/verify_tar_gz_root.sh`, which
calls `dockerfiles/verify_packages/tar_gz/install.sh` (installs PHP from sury),
extracts the tar.gz to `/`, checks ownership, runs `post-install.sh`, then
`php --ri=ddtrace`.  Uses `--root` (needs apt and writes to `/opt`).

```bash
.claude/ci/dockerh --cache verify-targz --overlayfs --root \
  debian:bullseye-slim \
  -v /path/to/packages:/artifacts:ro \
  -e PHP_VERSION=7.0 \
  -- bash -c '
    mkdir -p build/packages
    cp /artifacts/datadog-php-tracer-*.x86_64.tar.gz build/packages/
    ./dockerfiles/verify_packages/verify_tar_gz_root.sh
  '
```

Note: CI uses PHP 7.0 on amd64, PHP 8.1 on arm64.

### verify no json ext

The CI `before_script` is the same as `verify alpine` (the
`&verify_alpine_before_script` anchor): `mkdir build; mv packages build;
apk add ca-certificates curl`.  The `script` runs
`./dockerfiles/verify_packages/verify_no_ext_json.sh`.  Uses `--root` (needs
apk).

```bash
.claude/ci/dockerh --cache verify-nojson --overlayfs --root \
  alpine:3.12 \
  -v /path/to/packages:/artifacts:ro \
  -- sh -c '
    mkdir -p build/packages
    cp /artifacts/*.apk build/packages/
    apk add --no-cache ca-certificates curl
    ./dockerfiles/verify_packages/verify_no_ext_json.sh
  '
```

### pecl tests

Runs as non-root (no `--root`).

```bash
.claude/ci/dockerh --cache pecl-8.3 --overlayfs --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-6 -- \
  bash -c '
    cp /project/dd-trace-php/pecl/datadog_trace-*.tgz ./datadog_trace.tgz
    pecl install datadog_trace.tgz
    echo "extension=ddtrace.so" | sudo tee $(php -i | awk -F"=> " "/Scan this dir/ {print \$2}")/ddtrace.ini
    php --ri=ddtrace
  '
```

### Loader tests (libc)

Runs as non-root (no `--root`).  The CI `before_script` sets
`XDEBUG_SO_NAME` per PHP version, calls `switch-php $PHP_FLAVOUR`, extracts
the SSI package, and copies the loader `.so` into `loader/modules/`.  With
`--overlayfs` the repo is read-only underneath, so copy `loader/` to `/tmp`
first.

```bash
.claude/ci/dockerh --cache loader-8.3 --overlayfs --php nts \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  -v /path/to/packages:/artifacts:ro \
  -- bash -c '
    export XDEBUG_SO_NAME=xdebug-3.3.2.so
    mkdir -p extracted/
    tar --no-same-owner --no-same-permissions --touch \
      -xzf /artifacts/dd-library-php-ssi-*-linux.tar.gz -C extracted/
    export DD_LOADER_PACKAGE_PATH=${PWD}/extracted/dd-library-php-ssi
    cp -a loader /tmp/loader-work && cd /tmp/loader-work
    mkdir -p modules
    cp ${DD_LOADER_PACKAGE_PATH}/linux-gnu/loader/dd_library_loader.so modules/
    ./bin/test.sh
    ./bin/check_glibc_version.sh
  '
```

### Loader tests (Alpine musl)

Installs PHP from apk → use `--root`.  The CI `before_script` installs
`curl-dev php83 php83-dev php83-pecl-xdebug bash`, sets `XDEBUG_SO_NAME`,
extracts the SSI package in-place, and copies the musl loader `.so`.

```bash
.claude/ci/dockerh --cache loader-alpine --overlayfs --root \
  alpine:3.20 \
  -v /path/to/packages:/artifacts:ro \
  -- sh -c '
    apk add --no-cache curl-dev php83 php83-dev php83-pecl-xdebug bash
    export XDEBUG_SO_NAME=xdebug.so
    tar -xzf /artifacts/dd-library-php-ssi-*-x86_64-linux.tar.gz
    export DD_LOADER_PACKAGE_PATH=${PWD}/dd-library-php-ssi
    cp -a loader /tmp/loader-work && cd /tmp/loader-work
    mkdir -p modules
    cp ${DD_LOADER_PACKAGE_PATH}/linux-musl/loader/dd_library_loader.so modules/
    ./bin/test.sh
  '
```

## Gotchas

- `verify centos` targets CentOS 7 which is **EOL**. The job contains
  workarounds to use `vault.centos.org` instead of the defunct
  `mirrorlist.centos.org`, with retry logic for the unreliable vault mirror.
  These jobs may flake due to mirror issues.

- The `INSTALL_TYPE` dimension has two values: `php_installer` (uses
  `datadog-setup.php`, the recommended end-user path) and `native_package`
  (uses distro package manager directly). Both must pass for a release.

- `verify no json ext` specifically tests on Alpine 3.12 without the
  `php7-json` package. The JSON extension was bundled into PHP core starting
  with PHP 8.0, so this test is relevant for PHP 7.x on Alpine where JSON is a
  separate package that users might not install.

- `Loader test on amd64 libc` includes a `USE_VALGRIND: "true"` matrix
  dimension for PHP 7.4+ that enables Valgrind leak checking. The arm64 variant
  does not have this (Valgrind is too slow on emulated arm64).

- `test early PHP 8.1` deliberately removes the opcache ini file (`rm
  /etc/php/8.1/cli/conf.d/10-opcache.ini`) and blanks the sources_path setting
  to test the extension in a minimal configuration matching what early Ubuntu
  22.04 users would have.

- `x-profiling phpt tests on Alpine` uses the Alpine **compile** images
  (`php-compile-extension-alpine-{ver}`), not the regular bookworm CI images,
  because it needs a musl-based PHP installation.

- The `verify` jobs use the `request-replayer` service container (not the
  test-agent) as a mock trace backend. It records raw trace payloads at
  `/replay` for assertions.
