# Compile / Build Artifact Jobs

These jobs produce the compiled `.so`, `.a`, `.dll`, and sidecar
binaries consumed by test jobs (Groups B, C, E, F) and packaging
jobs (Group I). They run in the `compile` stage (tracer pipeline)
and the `prepare` / `profiler` / `appsec` / `tracing` stages
(package pipeline).

## Build Conventions

Most build scripts post-process output `.so` files with
`objcopy --compress-debug-sections` (exceptions: `compile_extension.sh`,
`build-appsec-helper-rust.sh`, and `build-loader.sh`). Debug symbols are retained but compressed.
If you need to run this step outside a build script and your host lacks `binutils`:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug \
    datadog/dd-trace-ci:php-8.3_bookworm-6 -- \
    objcopy --compress-debug-sections /project/dd-trace-php/tmp/build_extension/modules/ddtrace.so
```

## CI Jobs

**Source:**
- `.gitlab/generate-tracer.php` -- generates the tracer-trigger child pipeline;
  defines `compile extension: debug` and `compile extension: debug-zts-asan`
- `.gitlab/generate-package.php` -- generates the package-trigger child pipeline;
  defines all other compile/link/aggregate jobs listed below
- `.gitlab/generate-common.php` -- shared PHP-version and arch matrices
- `.gitlab/compile_extension.sh` -- build script for tracer-pipeline `compile extension` jobs
- `compile_rust.sh` -- shared Rust build wrapper invoked by `compile_extension.sh`
  and `build-sidecar.sh`; sets `RUSTFLAGS`, `RUSTC_BOOTSTRAP=1`, and `SIDECAR_VERSION`
- `.gitlab/build-tracing.sh` -- builds NTS + ZTS `.a` (static archives) for the package pipeline
- `.gitlab/build-sidecar.sh` -- builds `libddtrace_php.{a,so}` (Rust sidecar)
- `.gitlab/link-tracing-extension.sh` -- links `.a` archives with the sidecar into final `.so` files
- `.gitlab/build-appsec.sh` -- builds `ddappsec-{ABI}.so` (NTS + ZTS)
- `.gitlab/build-appsec-helper.sh` -- builds `libddappsec-helper.so` (C++ helper, musl toolchain)
- `.gitlab/build-appsec-helper-rust.sh` -- builds `libddappsec-helper-rust.so` (Rust helper, musl nightly)
- `.gitlab/build-loader.sh` -- builds `dd_library_loader.so` (SSI loader)
- `.gitlab/build-profiler.sh` -- builds profiler extension (NTS + ZTS)

### Tracer pipeline (generate-tracer.php)

| CI Job | Image | What it does |
|--------|-------|--------------|
| `compile extension: debug` | `dd-trace-ci:php-{ver}_bookworm-6` | Runs `append-build-id.sh` to stamp VERSION; compiles Rust (`compile_rust.sh`, debug profile) and C (`make -j static`) in parallel; `make static` also builds `php_sidecar_mockgen` (a secondary Rust build generating `mock_php.c` stubs); rewrites ldflags via `sed -i`; links `ddtrace.a` + `libddtrace_php.a` → `ddtrace.so` with `-soname ddtrace.so`. Sets `SHARED=1` (adds `--cfg php_shared_build` to `RUSTFLAGS`). |
| `compile extension: debug-zts-asan` | `dd-trace-ci:php-{ver}_bookworm-6` | Same as `compile extension: debug` (inherits `SHARED=1` via `extends:`) but with `WITH_ASAN=1` (sets `ASAN=1`+`COMPILE_ASAN=1`) and `SWITCH_PHP_VERSION=debug-zts-asan`; produces `ddtrace.so` instrumented with AddressSanitizer for ASAN test jobs |
| `Prepare code` | `php:8.2-cli` | Runs `composer update` + `make generate` to produce `src/bridge/_generated_*.php` |

Runner: `arch:{amd64,arm64}`
Matrix (`compile extension: debug`): PHP 7.0--8.5 x {amd64, arm64}
Matrix (`compile extension: debug-zts-asan`): PHP 7.4--8.5 x {amd64, arm64}

**Note on `Prepare code` vs `prepare code`:** These are two distinct jobs. The tracer
pipeline `Prepare code` uses `php:8.2-cli` (which has no Composer), installs Composer
from scratch, runs `composer update` + `make generate`, and lives in the `compile`
stage. The package pipeline `prepare code` uses `composer:2`, runs
`append-build-id.sh` first (bumping VERSION to `{major}.{minor+1}.0+{CI_COMMIT_SHA}` on
non-release branches; for pre-release versions like `1.2.3-beta1` it strips the
suffix to produce `1.2.3+{CI_COMMIT_SHA}` instead; no-op on tags and `ddtrace-`
release branches), then `composer self-update` + `composer update`
+ `make generate`, and lives in the `prepare` stage. `make generate` produces three
files via `classpreloader`: `_generated_api.php`, `_generated_tracer.php`, and
`_generated_opentelemetry.php`.

### Package pipeline (generate-package.php)

| CI Job | Image | What it does |
|--------|-------|--------------|
| `prepare code` | `composer:2` | `.gitlab/append-build-id.sh` (bumps VERSION first) + `composer self-update` + `composer update` + `make generate`; produces VERSION + generated bridge files |
| `cache cargo deps: [{arch}, {triplet}]` | `dd-trace-ci:php-8.1_{platform}` (alpine uses `php-compile-extension-alpine-8.1`) | `cargo fetch` to warm the Cargo cache for the given target triplet |
| `compile tracing extension: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_{platform}` | Builds NTS + debug + ZTS static archives (`.a`) and standalone `.so` via `build-tracing.sh` (debug skipped on alpine); outputs `ddtrace-{PHP_API}{suffix}[-debug\|-zts].{a,so}` under `extensions_{arch}/` and `standalone_{arch}/` |
| `compile tracing sidecar: [{arch}, {triplet}]` | `dd-trace-ci:php-8.1_{platform}` | Builds `libddtrace_php.{a,so}` (FFI bridge library; `ddtrace-php` crate in `components-rs/`) via `build-sidecar.sh` → `compile_rust.sh` → `cargo build`; profile `tracer-release` (LTO, 1 codegen unit, panic=abort); `RUSTFLAGS=--cfg tokio_unstable --cfg php_shared_build`; `SIDECAR_VERSION` embedded from `VERSION` file |
| `link tracing extension: [{arch}, {triplet}]` | `dd-trace-ci:php-8.1_{platform}` | Rewrites `-export-symbols` → `-Wl,--retain-symbols-file` in the `.ldflags` file via `sed -i`; links each per-version `.a` in `extensions_$(uname -m)/` against `libddtrace_php_$(uname -m)${suffix}.a` with `-whole-archive` and the rewritten ldflags, setting `-soname ddtrace.so`; all links run in parallel background processes; post-processes each `.so` with `objcopy --compress-debug-sections` |
| `aggregate tracing extension: [{arch}]` | `dd-trace-ci:php-7.4_bookworm-6` | No-op `ls` that aggregates artifacts from all `compile tracing extension` jobs for one arch into a single artifact set |
| `compile tracing extension asan: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_bookworm-6` | Switches to `debug-zts-asan` PHP; builds `ddtrace.so` directly with `RUST_DEBUG_BUILD=1` (Rust debug profile, no `.a` intermediate); copies to `extensions_$(uname -m)/ddtrace-${ABI_NO}-debug-zts.so`; post-processes with `objcopy --compress-debug-sections` |
| `compile appsec extension: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_{platform}` | Builds NTS and ZTS appsec extensions sequentially via cmake+make in `appsec/build/` and `appsec/build-zts/`; cmake flags: `-DCMAKE_BUILD_TYPE=RelWithDebInfo -DDD_APPSEC_BUILD_HELPER=OFF -DDD_APPSEC_TESTING=OFF -DDD_APPSEC_EXTENSION_STATIC_LIBSTDCXX=ON`; outputs `appsec_$(uname -m)/ddappsec-$PHP_API${suffix}[-zts].so`; post-processes with `objcopy --compress-debug-sections` |
| `compile appsec helper` | `registry.ddbuild.io/images/mirror/b1o7r7e0/nginx_musl_toolchain` (original gone) | Builds `libddappsec-helper.so` via cmake+make with musl toolchain (`-DCMAKE_TOOLCHAIN_FILE=/sysroot/$(arch)-none-linux-musl/Toolchain.cmake`); `DD_APPSEC_ENABLE_PATCHELF_LIBC=ON` strips musl libc dependency via patchelf; runs gtest suite (`make ddappsec_helper_test && ./tests/helper/ddappsec_helper_test`); copies `recommended.json` to `appsec_$(uname -m)/` |
| `compile appsec helper rust` | `dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl` | Builds `libddappsec-helper-rust.so` via `cargo +nightly-$RUST_TARGET` with `--release -Zhost-config -Ztarget-applies-to-host --target $(uname -m)-unknown-linux-musl`; removes musl libc dep with `patchelf --remove-needed`; runs `cargo +nightly-$RUST_TARGET test --release` after build; output in `appsec_$(uname -m)/` |
| `compile profiler extension: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_{platform}` | Builds NTS and ZTS profiler extensions via `cargo build --profile profiler-release` in `profiling/`; for ZTS, `touch build.rs` forces the build script to re-run after `switch-php` to pick up ZTS headers; outputs `datadog-profiling[-zts].so` under a prefix dir; on alpine+aarch64 symlinks clang17 over clang20 to work around a bindgen incompatibility |
| `compile loader: [{host_os}, {arch}]` | `dd-trace-ci:php-8.3_{platform}` (alpine: `php-compile-extension-alpine-8.3`) | Builds `dd_library_loader-$(uname -m)-${HOST_OS}.so` (SSI loader) via `phpize`+`configure`+`make` in `loader/`; on musl installs build deps via `apk add`; embeds `PHP_DD_LIBRARY_LOADER_VERSION` from `VERSION` file in CFLAGS |
| `compile extension windows: [{ver}]` | `dd-trace-ci:php-{ver}_windows` | Runs a long-lived container via `docker run -d` + `docker exec`; builds NTS then ZTS via `phpize.bat` + `configure.bat --enable-debug-pack` + `nmake`; reuses NTS Rust `target/` for ZTS by moving it; outputs `extensions_x86_64/php_ddtrace-${ABI_NO}[-zts].dll` and `.pdb` debug symbols |
| `pecl build` | `dd-trace-ci:php-7.4_bookworm-6` | Runs `tooling/bin/pecl-build` via `make build_pecl_package`; regenerates PHP bridge files via `composer -dtooling/generation`; mutates `package.xml` (version, date, file list) and `Cargo.toml` (strips profiling workspace member) in-place; produces `datadog_trace-*.tgz` via `pear package`; requires a clean tree to re-run |

Runner: `arch:{amd64,arm64}` (Linux jobs) or `windows-v2:2019` (Windows)
Matrix (tracing/appsec extension): PHP 7.0--8.5 x 4 build platforms (x86_64-alpine-linux-musl, aarch64-alpine-linux-musl, x86_64-unknown-linux-gnu, aarch64-unknown-linux-gnu)
Matrix (profiler extension): PHP 7.1--8.5 x same 4 platforms
Matrix (ASAN tracing): PHP 7.4--8.5 x {x86_64-unknown-linux-gnu, aarch64-unknown-linux-gnu}
Matrix (Windows): PHP 7.2--8.5

## What It Builds

The package pipeline compile stage has a two-phase structure for the tracing extension:

1. **Phase 1 -- per-version compilation:** `compile tracing extension` produces a `.a`
   static archive in `extensions_$(uname -m)/` and a standalone `.so` in
   `standalone_$(uname -m)/` for each PHP version (per ABI). The `.a` is consumed by
   the link phase; the standalone `.so` is consumed by `aggregate tracing extension`
   (for `package loader`). This is PHP-version-specific because each PHP ABI requires
   different headers. At the same time, `compile tracing sidecar` builds the Rust
   sidecar library (one per platform, not per PHP version).

2. **Phase 2 -- linking:** `link tracing extension` takes all the per-version `.a` archives
   and links each one against the single sidecar `.a` to produce the final `.so` shared
   objects. This is done in parallel (one process per archive).

**Aggregation (sibling of linking):** `aggregate tracing extension` is a pass-through
job that collects the per-version `.a` archives, standalone `.so` files, and `.ldflags`
from all `compile tracing extension` jobs for one architecture into a single artifact
set. Its sole downstream consumer is `package loader`. Note that `link tracing
extension` and `aggregate tracing extension` are siblings -- both depend on
`compile tracing extension` -- not sequential phases.

The `compile extension: debug` jobs in the **tracer pipeline** are simpler: they compile
Rust + C in parallel and produce a single `ddtrace.so` per PHP version. These are used
by the test jobs, not by the packaging pipeline.

## Build Platforms

| Triplet | Arch | Host OS | Package targets |
|---------|------|---------|-----------------|
| `x86_64-alpine-linux-musl` | amd64 | linux-musl | `.apk.x86_64` |
| `aarch64-alpine-linux-musl` | arm64 | linux-musl | `.apk.aarch64` |
| `x86_64-unknown-linux-gnu` | amd64 | linux-gnu | `.rpm.x86_64`, `.deb.x86_64`, `.tar.gz.x86_64` |
| `aarch64-unknown-linux-gnu` | arm64 | linux-gnu | `.rpm.arm64`, `.deb.arm64`, `.tar.gz.aarch64` |
| `x86_64-pc-windows-msvc` | amd64 | windows-msvc | `dbgsym.tar.gz` |

## Dependency Graph

```
prepare code          cache cargo deps: [{arch}, {triplet}]
  |                     |
  |                     +-- compile tracing sidecar: [{arch}, {triplet}]*
  |                     |     |
  |                     |     +----.
  |                     |          |
  |                     +-- compile profiler extension: [{ver}, {arch}, {triplet}]*
  |
  +-- compile tracing extension: [{ver}, {arch}, {triplet}]   (prepare code only)
  |     |
  |     +-- aggregate tracing extension: [{arch}]
  |     +-- link tracing extension: [{arch}, {triplet}]  <-- also needs compile tracing sidecar
  |
  +-- compile tracing extension asan: [{ver}, {arch}, {triplet}]
  +-- compile appsec extension: [{ver}, {arch}, {triplet}]
  +-- compile appsec helper
  +-- compile appsec helper rust
  +-- compile loader: [{host_os}, {arch}]
  +-- compile extension windows: [{ver}]
  +-- pecl build

* also needs prepare code (not shown to keep the graph readable)
```

## Gotchas

- The tracer pipeline's `compile extension: debug` and the package pipeline's `compile
  tracing extension` are **different jobs** that produce differently-structured artifacts.
  The tracer pipeline version produces a ready-to-load `ddtrace.so`; the package pipeline
  version produces static `.a` archives that need a separate link step.

- `link tracing extension` uses the `.ldflags` file generated during `compile tracing
  extension` for the PHP 7.0 build specifically (`ddtrace_$(uname -m)${suffix}.ldflags`).
  The ldflags file contains the linker symbol-retention flags needed for all versions.

- `aggregate tracing extension` does not actually compile or link anything -- its `script:`
  is literally `ls ./`. Its sole purpose is to fan-in pre-link artifacts (`.a` archives,
  standalone `.so` files, `.ldflags`) from all per-version `compile tracing extension`
  jobs into a single artifact set for `package loader`.

- `compile appsec helper rust` uses `cargo +nightly` with `-Zhost-config
  -Ztarget-applies-to-host` to cross-compile for musl, then `patchelf --remove-needed`
  to strip the musl libc dependency, making the binary work on both musl and glibc systems.

- `compile appsec helper` (C++) runs its gtest suite as part of the build (`make
  ddappsec_helper_test && ./tests/helper/ddappsec_helper_test`). A test failure will
  fail the compile job.

- `compile tracing sidecar` on alpine: the `-alpine` suffix variant force-installs
  `bindgen-cli` via `cargo install --force --locked` before building, as a workaround
  for `aws-lc-sys` build failures on musl targets.

- The Cargo cache uses the default `pull-push` policy in `cache cargo deps` and
  `policy: pull` (read-only) in `compile profiler extension` and `compile tracing
  sidecar`. `compile tracing extension` has no `cache:` block at all (this is expected
  since it runs `make static`, a pure C/PHP build; the Rust compilation is
  handled by `compile tracing sidecar`).

- Windows compile jobs use Docker on the Windows runner (not DinD): the script starts a
  long-lived container with `docker run -d`, then drives it via `docker exec`. The
  `GIT_STRATEGY: none` variable means the runner does not clone the repo -- instead the
  job script manually clones via `git clone` + `git checkout`.

- `ddtrace.sym` (repo root) is the export list for the final `ddtrace.so`. All symbols
  not listed are hidden via `--retain-symbols-file` + `-fvisibility=hidden`. If you add
  a new function that must be callable from appsec, profiler, or the SSI loader, add it
  to `ddtrace.sym` or the linker will drop it.

- `CARGO_TARGET_DIR` must not be set explicitly for `compile_rust.sh`. The default
  (`target`) is resolved relative to the workspace root by Cargo. An explicit value
  becomes CWD-relative; since `compile_rust.sh` `cd`s into `components-rs/`, this
  silently breaks the build.

- ASAN artifacts in the package pipeline have no "asan" in their filename:
  `compile tracing extension asan` outputs `ddtrace-{ABI}-debug-zts.so`, which is
  indistinguishable from a non-ASAN debug-zts build by filename alone.

- Windows Cargo profile is `debug`: `config.w32` hardcodes
  `ddtrace_cargo_profile = "debug"`. Unlike all Linux builds, the Windows `.dll` ships
  with unoptimized Rust code.

- Submodule requirements: `compile tracing sidecar` and
  `compile extension: debug` need the `libdatadog` submodule;
  `compile appsec helper rust` needs
  `appsec/third_party/libddwaf-rust`. Local runs need
  `git submodule update --init --recursive` before building.

- **centos-7 vs bookworm images — do not mix them.** The package-pipeline
  `compile tracing extension` jobs for `x86_64-unknown-linux-gnu` and
  `aarch64-unknown-linux-gnu` use **centos-7** images (targeting GLIBC 2.17
  for maximum compatibility), not bookworm. Only the ASAN variant
  (`compile tracing extension asan`) and the tracer-pipeline
  `compile extension: debug` jobs use bookworm. Using the wrong image causes
  the `switch-php` and `BASH_ENV` failures described below.

- **`switch-php` variant naming differs between centos and bookworm.** On
  centos-7 images, PHP variants under `/opt/php/` are version-prefixed:
  `8.3`, `8.3-debug`, `8.3-zts`. On bookworm images, variants are bare names:
  `nts`, `debug`, `zts`, `nts-asan`, `debug-zts-asan`. `build-tracing.sh`
  calls `switch-php "${PHP_VERSION}"` (e.g. `switch-php 8.3`), which works on
  centos but fails on bookworm. Conversely, `compile_extension.sh` uses
  `switch-php debug` / `switch-php debug-zts-asan` (bookworm names).

- **`CARGO_HOME=/rust/cargo/` is root-owned in CI images.** See
  [building-locally.md](building-locally.md#cargo_home-is-root-owned-in-ci-images)
  for the workaround. This affects `build-sidecar.sh` and any other
  Rust build that does not use `--root`.

- **Alpine images use a different naming convention.** Alpine/musl compile
  images follow the pattern `php-compile-extension-alpine-{ver}` (e.g.
  `php-compile-extension-alpine-8.3`), not the `php-{ver}_{os}-{N}` pattern
  used by bookworm/centos images.

- **`compile loader` on musl requires `--root`.** `build-loader.sh` runs
  `apk add` to install build dependencies on Alpine, which needs root. This
  only applies to the musl variant; the linux-gnu variant runs fine without
  `--root`.

- **`compile appsec extension` is pure C/C++ — no Rust/Cargo.** Unlike
  tracing and profiler compile jobs, this build has no Cargo dependency and
  no cache block. `DD_APPSEC_BUILD_HELPER=OFF` skips the heavy helper
  dependencies (libddwaf, googletest, etc.); only the extension `.so` is
  built.

- **`compile appsec helper rust` sets `CARGO_TARGET_DIR` explicitly.**
  Unlike `compile_rust.sh` (where `CARGO_TARGET_DIR` must NOT be set),
  `build-appsec-helper-rust.sh` sets `CARGO_TARGET_DIR=/tmp/cargo-target`
  intentionally. The `build.rs` also embeds `DDAPPSEC_VERSION` from the
  `VERSION` file, which is why this job depends on `prepare code`.

- **`compile extension: debug` Rust profile.** The "debug" in the job name
  refers to the PHP debug build variant, not the Rust profile — but
  coincidentally the Rust code also builds with the `debug` (dev) profile
  (unoptimized). CI also sets `SHARED=1`, which adds `--cfg php_shared_build`
  to `RUSTFLAGS`. The `debug-zts-asan` job inherits `SHARED=1` via
  `extends:` — it is not visible in the job definition itself; do not omit
  it when reproducing locally.

- **`CI_COMMIT_BRANCH` on detached HEAD.** When running on a detached
  HEAD (e.g., after `git checkout <sha>`), `git rev-parse --abbrev-ref HEAD`
  returns the literal string `HEAD`. `append-build-id.sh` still works, but
  the embedded version string will contain `HEAD` as the branch name.

- **Silent final link step in `compile_extension.sh`.** The final `sed -i`
  + `cc -shared` commands produce no output (no `set -x`). On a successful
  build, the last visible log line is `compile_rust.sh`'s `Finished ...`
  message. Verify success by checking the output exists:
  ```bash
  docker run --rm -v dd-ci-<CACHE>:/v alpine \
    ls -lh /v/upper/tmp/build_extension/modules/ddtrace.so
  ```

- **`devtoolset-7` on centos-7.** The ancient CentOS 7 base ships GCC 4.8;
  `build-tracing.sh` activates `devtoolset-7` (GCC 7) via `scl_source`.
  This is specific to centos-7/glibc builds — bookworm has a modern GCC.

- **`compile loader` is the simplest compile job.** Pure C (phpize +
  configure + make), no Rust, no submodules, no `switch-php`. Takes seconds.
  `HOST_OS` affects the output filename and controls whether
  `apk add` installs build dependencies (musl only); `config.m4`
  independently detects musl at compile time by checking whether
  `ldd --version` output starts with `musl`. The build produces
  `loader/modules/dd_library_loader.so`, then copies it to the project
  root as `dd_library_loader-$(uname -m)-${HOST_OS}.so` (e.g.,
  `dd_library_loader-x86_64-linux-gnu.so`).

## Local Reproduction

For a quick-reference guide to building each artifact locally, see
[building-locally.md](building-locally.md). The commands below are
exact CI job equivalents with full environment variables.

Use `.claude/ci/dockerh` (see `index.md`). Pass `CI_COMMIT_SHA` and
`CI_COMMIT_BRANCH` from the host so `append-build-id.sh` embeds the
correct version string.

**Expected build times (first run, empty cache):**

| Job | arm64 (Apple Silicon) | amd64 (Linux) |
|-----|-----------------------|---------------|
| compile extension: debug | ~2 min | ~2 min |
| compile extension: debug-zts-asan | ~2 min | ~2 min |
| compile tracing extension (per version) | — | ~1.5 min |
| compile tracing sidecar | — | ~3 min |
| compile appsec extension (per version) | ~2 min | ~2 min |
| compile appsec helper rust | ~3 min | ~3 min |
| compile profiler extension (per version) | — | ~2 min |
| compile loader | ~4 sec | ~4 sec |

Subsequent runs with cached Rust artifacts: C-only changes rebuild
in ~10 s; Rust changes in ~30–60 s.

Scripts that call `switch-php` internally (`compile_extension.sh`,
`build-tracing.sh`, `build-appsec.sh`, `build-profiler.sh`) need root to
modify `/usr/local/bin/` symlinks. Use `--root` for these — do **not** use
`--php` since the script already handles variant switching. Scripts that do
not call `switch-php` (`build-sidecar.sh`, `build-loader.sh`) run fine
without `--root` **on GNU/Linux images**. On Alpine (musl) images,
`build-loader.sh` requires `--root` because it runs `apk add` to install
build dependencies.

```bash
# compile extension: debug (tracer pipeline, PHP 8.3)
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_bookworm-6 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e SHARED=1 \
    -- bash .gitlab/compile_extension.sh

# compile extension: debug-zts-asan (tracer pipeline, PHP 8.3)
.claude/ci/dockerh --cache tracer-8.3-debug-zts-asan --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_bookworm-6 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e WITH_ASAN=1 \
    -e SWITCH_PHP_VERSION=debug-zts-asan \
    -e SHARED=1 \
    -- bash .gitlab/compile_extension.sh

# compile tracing extension (package pipeline, PHP 8.3, linux-gnu)
.claude/ci/dockerh --cache compile-tracing-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.3 bash .gitlab/build-tracing.sh'

# compile tracing sidecar (linux-gnu)
# CARGO_HOME override needed — see building-locally.md
# HOST_OS is passed through to compile_rust.sh to select the Rust target triplet
# (linux-gnu vs linux-musl). Use linux-gnu for glibc, linux-musl for Alpine.
.claude/ci/dockerh --cache compile-sidecar-gnu --overlayfs \
    datadog/dd-trace-ci:php-8.1_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e CARGO_HOME=/project/dd-trace-php/.cache/cargo \
    -- bash -c 'HOST_OS=linux-gnu bash .gitlab/build-sidecar.sh'

# compile appsec extension (PHP 8.3, linux-gnu)
.claude/ci/dockerh --cache compile-appsec-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.3 bash .gitlab/build-appsec.sh'

# compile profiler extension (PHP 8.3, linux-gnu)
.claude/ci/dockerh --cache compile-profiler-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.3 bash .gitlab/build-profiler.sh datadog-profiling/x86_64-unknown-linux-gnu/lib/php/20230831 nts'

# compile profiler extension ZTS variant (PHP 8.3, linux-gnu)
# Reuse the same cache — build-profiler.sh calls switch-php internally
.claude/ci/dockerh --cache compile-profiler-8.3-gnu --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'PHP_VERSION=8.3 bash .gitlab/build-profiler.sh datadog-profiling/x86_64-unknown-linux-gnu/lib/php/20230831 zts'

# compile loader (linux-gnu)
.claude/ci/dockerh --cache compile-loader-gnu --overlayfs \
    datadog/dd-trace-ci:php-8.3_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'HOST_OS=linux-gnu bash .gitlab/build-loader.sh'

# compile loader (linux-musl) -- requires --root for apk add
.claude/ci/dockerh --cache compile-loader-musl --overlayfs --root \
    datadog/dd-trace-ci:php-compile-extension-alpine-8.3 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash -c 'HOST_OS=linux-musl bash .gitlab/build-loader.sh'

# compile appsec helper rust
.claude/ci/dockerh --cache compile-appsec-helper-rust --overlayfs \
    datadog/dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- bash .gitlab/build-appsec-helper-rust.sh

# pecl build
.claude/ci/dockerh --cache compile-pecl --overlayfs \
    datadog/dd-trace-ci:php-7.4_bookworm-6 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- make build_pecl_package
```

`--overlayfs` is used for package pipeline jobs because their output directories
(`extensions_*/`, `standalone_*/`, `appsec_*/`, `datadog-profiling/`, etc.) are
written to the project root and to files like `VERSION` and `*.ldflags`. The
overlayfs mode mounts the checkout read-only as the lower dir and uses a Docker
named volume (`dd-ci-{NAME}`) as the upper dir, so all writes go to the volume
transparently via copy-up. This also handles `append-build-id.sh` modifying
`VERSION`, which would fail with a read-only mount.
