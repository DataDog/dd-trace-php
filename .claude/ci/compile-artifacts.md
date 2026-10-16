# Compile / Build Artifact Jobs

These jobs produce the compiled `.so`, `.a`, `.dll`, and sidecar
binaries consumed by test jobs (Groups B, C, E, F) and packaging
jobs (Group I). They run in the `compile` stage (tracer pipeline)
and the `prepare` / `combined` / `tracing` stages
(package pipeline).

## Build Conventions

Most build scripts post-process output `.so` files with
`objcopy --compress-debug-sections` (exceptions: `compile_extension.sh` and
`build-loader.sh`). Debug symbols are retained but compressed.
If you need to run this step outside a build script and your host lacks `binutils`:

```bash
.claude/ci/dockerh --cache tracer-8.3-debug \
    datadog/dd-trace-ci:php-8.3_bookworm-11 -- \
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
  for regular non-LTO builds; sets `RUSTFLAGS` and `SIDECAR_VERSION`
- `.gitlab/build-tracing.sh` -- legacy PHP 7.0 C archives and slim SSI extensions
- `.gitlab/build-sidecar.sh` -- builds the PHP-independent common runtime DSO
  and an intermediate archive through `tooling/bin/build-xlang-lto ssi-common`
- `tooling/bin/build-xlang-lto` -- matched C↔Rust ThinLTO release builds
- `.gitlab/build-ssi-combined-alpine.sh` -- NTS/ZTS SSI ThinLTO builds on musl
- `.gitlab/link-tracing-extension.sh` -- completes the legacy debug link,
  preserves prebuilt release DSOs, and removes intermediate `.a` files
- `.gitlab/build-loader.sh` -- builds `dd_library_loader.so` (SSI loader)
- `.gitlab/build-profiler.sh` -- regular isolated phpize/configure builds;
  release packaging uses combined mode for the PHP debug ABI

### Tracer pipeline (generate-tracer.php)

| CI Job | Image | What it does |
|--------|-------|--------------|
| `compile extension: debug` | `dd-trace-ci:php-{ver}_bookworm-11` | Runs `append-build-id.sh` to stamp VERSION; compiles Rust (`compile_rust.sh`, debug profile) and C (`make -j static`) in parallel; `make static` also builds `php_sidecar_mockgen` (a secondary Rust tool that weakens PHP symbol references, not a C-stub generator); links `ddtrace.a` + `libdatadog_php.a` → `ddtrace.so` with the generated target export list and `-soname ddtrace.so`. Sets `SHARED=1` (adds `--cfg php_shared_build` to `RUSTFLAGS`). |
| `compile extension: debug-zts-asan` | `dd-trace-ci:php-{ver}_bookworm-11` | Same as `compile extension: debug` (inherits `SHARED=1` via `extends:`) but with `WITH_ASAN=1` (sets `ASAN=1`+`COMPILE_ASAN=1`) and `SWITCH_PHP_VERSION=debug-zts-asan`; produces `ddtrace.so` instrumented with AddressSanitizer for ASAN test jobs |
| `Prepare code` | `php:8.2-cli` | Runs `composer update` + `make generate` to produce `src/bridge/_generated_*.php` |

Runner: `arch:{amd64,arm64}`
Matrix (`compile extension: debug`): PHP 7.0+ x {amd64, arm64}
Matrix (`compile extension: debug-zts-asan`): PHP 7.4+ x {amd64, arm64}

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
| `compile tracing extension: [7.0, {arch}, {triplet}]` | `dd-trace-ci:php-7.0_{platform}` | Tracer-only path: `build-tracing.sh` produces legacy C archives, then `make xlang-lto` and `make build-ssi-ddtrace` replace NTS/ZTS self-contained and SSI DSOs. SSI rebuilds common without a PHP-ABI archive and checks it against the sidecar job’s canonical DSO. Debug remains non-LTO (GNU only). Outputs `extensions_{arch}/`, `standalone_{arch}/`, and legacy link metadata. |
| `compile tracing sidecar: [{arch}, {triplet}]` | `dd-trace-ci:php-8.1_{platform}` | `build-sidecar.sh` uses `build-xlang-lto ssi-common` to build `libdatadog_php_{arch}[-alpine].so` (common runtime plus embedded AppSec helper), once per platform. Also retains a Rust archive solely for the legacy debug link; it is not shipped. |
| `link tracing extension: [{arch}, {triplet}]` | `dd-trace-ci:php-8.1_{platform}` | Fans in PHP 7.0 and PHP 7.1+ combined artifacts. Skips already-built `.so` files, links the remaining legacy debug archive with matched LLD, and deletes intermediate `.a` files from `extensions_*`. |
| `aggregate tracing extension: [{arch}]` | `dd-trace-ci:php-7.4_bookworm-11` | No-op `ls` that collects the completed link-job artifacts for ordinary extension packaging. |
| `compile tracing extension asan: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_bookworm-11` | Switches to `debug-zts-asan` PHP; builds `ddtrace.so` directly with `RUST_DEBUG_BUILD=1` (Rust debug profile, no `.a` intermediate); copies to `extensions_$(uname -m)/ddtrace-${ABI_NO}-debug-zts.so`; post-processes with `objcopy --compress-debug-sections` |
| `compile combined extension: [{ver}, {arch}, {triplet}]` | `dd-trace-ci:php-{ver}_{platform}` | PHP 7.1+ self-contained `ddtrace.so` with profiling and AppSec: `make xlang-lto` for NTS/ZTS on GNU and musl; `build-profiler.sh` for the non-LTO debug ABI on GNU. Copies results into `extensions_{arch}/ddtrace-{API}[-alpine][-debug\|-zts].so`. |
| `compile SSI combined extension: [{ver}, {arch}, {triplet}]` | same | PHP 7.1+ NTS/ZTS via `build-xlang-lto ssi-combined` (GNU) or `build-ssi-combined-alpine.sh` (musl). Each invocation co-builds common and the PHP-ABI archive, then requires complete common DSO identity with the sidecar job’s canonical copy. Outputs `ssi_{arch}/ddtrace-{API}[-alpine][-zts].so`; does not republish common per PHP ABI. |
| `aggregate SSI combined extension: [{arch}]` | `dd-trace-ci:php-7.4_bookworm-11` | Collects `ssi_*` from the SSI compile jobs for `package loader`. |
| `compile loader: [{host_os}, {arch}]` | `dd-trace-ci:php-8.3_{platform}` (alpine: `php-compile-extension-alpine-8.3`) | Builds `dd_library_loader-$(uname -m)-${HOST_OS}.so` (SSI loader) via `phpize`+`configure`+`make` in `loader/`; on musl installs build deps via `apk add`; embeds `PHP_DD_LIBRARY_LOADER_VERSION` from `VERSION` file in CFLAGS |
| `compile extension windows: [{ver}]` | `dd-trace-ci:php-{ver}_windows` | Runs a long-lived container via `docker run -d` + `docker exec`; builds NTS then ZTS via `phpize.bat` + `configure.bat --enable-debug-pack` + `nmake`; reuses NTS Rust `target/` for ZTS by moving it; outputs `extensions_x86_64/php_ddtrace-${ABI_NO}[-zts].dll` and `.pdb` debug symbols |
| `pecl build` | `dd-trace-ci:php-7.4_bookworm-11` | Runs `tooling/bin/pecl-build` via `make build_pecl_package`; regenerates PHP bridge files via `composer -dtooling/generation`; mutates `package.xml` (version, date, file list) and `Cargo.toml` (strips profiling workspace member) in-place; produces `datadog_trace-*.tgz` via `pear package`; requires a clean tree to re-run |

Runner: `arch:{amd64,arm64}` (Linux jobs) or `windows-v2:2019` (Windows)
Matrix (legacy tracing): PHP 7.0 x 4 build platforms (x86_64-alpine-linux-musl, aarch64-alpine-linux-musl, x86_64-unknown-linux-gnu, aarch64-unknown-linux-gnu)
Matrix (combined and SSI combined extension): PHP 7.1+ x same 4 platforms
Matrix (ASAN tracing): PHP 7.4+ x {x86_64-unknown-linux-gnu, aarch64-unknown-linux-gnu}
Matrix (Windows): PHP 7.2+

## What It Builds

The package pipeline has distinct self-contained and SSI products:

| Input | Contents / consumer |
|-------|---------------------|
| `extensions_<arch>/ddtrace-<API>[-alpine][-debug\|-zts].so` | Self-contained extensions for ordinary tarballs and system packages; combined tracer+profiling on PHP 7.1+, tracer-only on PHP 7.0. |
| `ssi_<arch>/ddtrace-<API>[-alpine][-zts].so` | PHP 7.1+ combined SSI extensions for `generate-ssi-package.sh`. |
| `standalone_<arch>/ddtrace-20151012[-alpine][-zts].so` | Only legacy PHP 7.0 tracer-only SSI; despite the directory name, these are not standalone profiler DSOs. |
| `libdatadog_php_<arch>[-alpine].so` | PHP-independent common DSO, installed once per libc/platform as SSI `loader/libdatadog_php.so`. |

`<arch>` means the artifact architecture (`x86_64` or `aarch64`), not
necessarily the packaging runner's architecture.

NTS/ZTS Linux release builds use cross-language ThinLTO with matched
Clang/LLD/Rust toolchains on both libc targets. The PHP debug ABI uses the
regular non-LTO build. Each profiling build uses its selected PHP SDK; SSI's
private PHP-ABI Rust archive is linked into `ddtrace.so`, never shipped.
The common DSO owns profile construction, aggregation, destruction and encoding/export,
but no PHP-ABI profiler code. The collector and uploader threads remain in the extension.
Its dependency features are fixed even for PHP 7.0, without enabling PHP profiling
or codegen. SSI jobs compare the full DSO (including debug data and embedded
helpers), retain mismatches as failure artifacts, and ship only the canonical
copy. Equality is per architecture/libc, never across families. SSI relies on
loader preload, not `DT_NEEDED(libdatadog_php.so)` or an added runpath.

`link tracing extension` is no longer an all-version relink phase: it preserves
prebuilt self-contained DSOs and completes only missing legacy links. Its
outputs feed `aggregate tracing extension` and ordinary packaging. SSI has
its own compile/aggregate path and feeds `package loader` separately.

Standalone `datadog-profiling.so` remains supported and is **built and tested,
but not shipped**: sanitizer jobs, older correctness cells, and package
product-loading comparisons still exercise it. Its test build output must not
be confused with the legacy tracer `standalone_*` directory above.

The tracer pipeline's `compile extension: debug` jobs instead compile Rust
and C in parallel and produce one ready-to-load tracer `ddtrace.so` per PHP
version for test jobs, not for release packaging.

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
prepare code + cache cargo deps
  +-- compile combined extension (PHP 7.1+) ------------------.
  +-- compile tracing extension (PHP 7.0) -------------------+-- link tracing extension
  +-- compile tracing sidecar (common DSO + legacy archive) -'       |
  |       |                                                        v
  |       |                                        aggregate tracing extension
  |       |                                                        |
  |       |                                           ordinary extension packages
  |       v
  +-- compile SSI combined extension (PHP 7.1+) --> aggregate SSI combined extension
  |                                                               |
  +-- compile loader ---------------------------------------------+-- package loader
                                                                     (+ common DSO
                                                                      and PHP 7.0 SSI)
```

The separate ASAN and Windows
compile paths feed their respective package jobs; PECL builds its own package.
See `.gitlab/generate-package.php` for the exact per-platform `needs:` lists.

## Gotchas

- Tracer debug, self-contained combined, and SSI combined builds have different
  consumers. Do not replace SSI inputs with `extensions_*` merely because the
  filenames match; those self-contained binaries embed their own runtime.

- `link tracing extension` uses PHP 7.0 fat-link flags/exports only for
  variants without a prebuilt DSO. `aggregate tracing extension` is a no-op
  fan-in **after linking**, not a collector of all-version pre-link archives.

- `compile tracing sidecar` on alpine: the `-alpine` suffix variant force-installs
  `bindgen-cli` via `cargo install --force --locked` before building, as a workaround
  for `aws-lc-sys` build failures on musl targets.

- The Cargo cache uses the default `pull-push` policy in `cache cargo deps` and
  `policy: pull` (read-only) in `compile combined extension`, `compile SSI combined
  extension`, and `compile tracing sidecar`. The PHP 7.0 `compile tracing extension`
  has no explicit `cache:` block, but its release ThinLTO steps do build Rust.

- Windows compile jobs use Docker on the Windows runner (not DinD): the script starts a
  long-lived container with `docker run -d`, then drives it via `docker exec`. The
  `GIT_STRATEGY: none` variable means the runner does not clone the repo -- instead the
  job script manually clones via `git clone` + `git checkout`.

- The `ddtrace.so` export lists are composed from disjoint symbol sets.
  `ddtrace-extension*.sym` contains extension exports and
  `components-rs/libdatadog-php*.sym` contains Rust exports. The generated
  `ddtrace-slim.sym` contains only extension exports; `ddtrace-fat.sym` also
  contains the embedded Rust exports. All other symbols are hidden via
  `--retain-symbols-file` and `-fvisibility=hidden`.

- `CARGO_TARGET_DIR` is owned by the top-level build path. `config.m4` forwards
  configured target directories to the root Cargo package for combined and
  standalone-profiler Make builds. Scripts that call `compile_rust.sh` directly
  must likewise pass a workspace-root-relative or absolute target directory and
  consume the matching output.

- ASAN artifacts in the package pipeline have no "asan" in their filename:
  `compile tracing extension asan` outputs `ddtrace-{ABI}-debug-zts.so`, which is
  indistinguishable from a non-ASAN debug-zts build by filename alone.

- Windows Cargo profile is `debug`: `config.w32` hardcodes
  `ddtrace_cargo_profile = "debug"`. Unlike all Linux builds, the Windows `.dll` ships
  with unoptimized Rust code.

- Submodule requirements: combined/SSI builds, `compile tracing sidecar`, and
  `compile extension: debug` need `libdatadog` and
  `appsec/third_party/libddwaf-rust`. Local runs need
  `git submodule update --init --recursive` before building.

- **centos-7 vs bookworm images — do not mix them.** The package-pipeline
  legacy tracing, combined, SSI combined, and common-runtime jobs for GNU
  targets use **centos-7** images (targeting GLIBC 2.17
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

- **There is no AppSec job.** AppSec is built into every `ddtrace.so`
  (`--enable-ddtrace-appsec`); its Rust helper is part of the common runtime
  built by `compile tracing sidecar`.

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
[building-locally.md](building-locally.md), including the release NTS/ZTS/debug
recipe and local `build-ssi-ddtrace` target. The commands below reproduce the
individual scripts; they do not assemble a complete release matrix.

Use `.claude/ci/dockerh` (see `index.md`). Pass `CI_COMMIT_SHA` and
`CI_COMMIT_BRANCH` from the host so `append-build-id.sh` embeds the
correct version string.

Release ThinLTO builds also rebuild Rust std by default. Development-build
timings are not estimates for these release jobs.

Scripts that call `switch-php` internally (`compile_extension.sh`,
`build-tracing.sh`, `build-profiler.sh`) need root to
modify `/usr/local/bin/` symlinks. Use `--root` for these — do **not** use
`--php` since the script already handles variant switching. Scripts that do
not call `switch-php` (`build-sidecar.sh`, `build-loader.sh`) run fine
without `--root` **on GNU/Linux images**. On Alpine (musl) images,
`build-loader.sh` requires `--root` because it runs `apk add` to install
build dependencies.

```bash
# compile extension: debug (tracer pipeline, PHP 8.3)
.claude/ci/dockerh --cache tracer-8.3-debug --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_bookworm-11 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e SHARED=1 \
    -- bash .gitlab/compile_extension.sh

# compile extension: debug-zts-asan (tracer pipeline, PHP 8.3)
.claude/ci/dockerh --cache tracer-8.3-debug-zts-asan --overlayfs --root \
    datadog/dd-trace-ci:php-8.3_bookworm-11 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e WITH_ASAN=1 \
    -e SWITCH_PHP_VERSION=debug-zts-asan \
    -e SHARED=1 \
    -- bash .gitlab/compile_extension.sh

# PHP 7.0 legacy release builds require the generated compile/link job steps;
# build-tracing.sh alone produces only their initial C/slim-SSI inputs.

# compile tracing sidecar (linux-gnu)
# CARGO_HOME override needed — see building-locally.md
# build-xlang-lto selects the native target/toolchain from the release image.
.claude/ci/dockerh --cache compile-sidecar-gnu --overlayfs \
    datadog/dd-trace-ci:php-8.1_centos-7 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -e CARGO_HOME=/project/dd-trace-php/.cache/cargo \
    -- bash -c 'HOST_OS=linux-gnu bash .gitlab/build-sidecar.sh'

# Combined release NTS/ZTS/debug and local SSI builds:
# use the recipes in building-locally.md, not build-profiler.sh for release NTS/ZTS.

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

# pecl build
.claude/ci/dockerh --cache compile-pecl --overlayfs \
    datadog/dd-trace-ci:php-7.4_bookworm-11 \
    -e CI_COMMIT_SHA=$(git rev-parse HEAD) \
    -e CI_COMMIT_BRANCH=$(git rev-parse --abbrev-ref HEAD) \
    -- make build_pecl_package
```

`--overlayfs` is used for package pipeline jobs because their output directories
(`extensions_*/`, `ssi_*/`, `standalone_*/`, etc.) are
written to the project root and to files like `VERSION` and `*.ldflags`. The
overlayfs mode mounts the checkout read-only as the lower dir and uses a Docker
named volume (`dd-ci-{NAME}`) as the upper dir, so all writes go to the volume
transparently via copy-up. This also handles `append-build-id.sh` modifying
`VERSION`, which would fail with a read-only mount.
