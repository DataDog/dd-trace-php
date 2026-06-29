# Packaging and OCI Publishing

## CI Jobs

**Source:**
- `.gitlab/generate-package.php` — generates the package-trigger child pipeline;
  all `script:` sections defined inline
- `.gitlab/prepare-oci-package.sh` — unpacks loader tar.gz and
  strips debug files before OCI publish
- `tooling/bin/generate-final-artifact.sh` — assembles per-triplet release packages
- `tooling/bin/generate-ssi-package.sh` — assembles SSI (loader) package
- `.gitlab/one-pipeline.locked.yml` — includes the shared one-pipeline template
  that defines all OCI, promotion, and publishing jobs

All compile, link, and aggregate jobs (`compile tracing extension`,
`compile tracing sidecar`, `link tracing extension`, `aggregate tracing extension`,
`compile appsec extension`, `compile appsec helper rust`,
`compile profiler extension`, `compile loader`, `compile extension windows`) are
documented in [compile-artifacts.md](compile-artifacts.md).

| CI Job | Image | What it does |
|--------|-------|--------------|
| `package extension: [{arch}, {triplet}]` | `dd-trace-ci:php_fpm_packaging` | Assembles `.deb`, `.rpm`, `.tar.gz`, `.apk` packages for one platform |
| `package loader: [{arch}]` | same | Assembles the SSI loader package |
| `datadog-setup.php` | same | Builds the `datadog-setup.php` installer via `make` |
| `requirements_json_test` | (one-pipeline template) | Validates `loader/packaging/block_tests.json` / `allow_tests.json` |
| `validate_supported_configurations_v2_local_file` | (one-pipeline template) | Validates `metadata/supported-configurations.json` against central schema |
| `package-oci` | (one-pipeline template) | Packages loader artifacts into an OCI image layer |
| `oci-internal-publish` | (one-pipeline template) | Publishes the OCI image to internal ECR |
| `create-multiarch-lib-injection-image` | (one-pipeline template) | Creates amd64+arm64 multi-arch manifest |
| `kubernetes-injection-test-ecr-publish` | (one-pipeline template) | Publishes to ECR for Kubernetes injection tests |
| `promote-oci-to-staging` / `prod-beta` / `prod` | (one-pipeline template) | Progressive OCI promotion |
| `publishing-gate` | (one-pipeline template) | Final gate before production promotion |
| `publish to public s3` | `amazon/aws-cli:2.17.32` | Uploads packages to `s3://dd-trace-php-builds/{VERSION}/` |
| `publish release to github` | `php:8.2-cli` | Creates GitHub release + uploads assets (release branches only) |
| `bundle for reliability env` | `ci_docker_base:67145216` | Bundles setup script + tar for the reliability env |

Runner: `arch:amd64` for all packaging and publishing jobs.

Platform matrix for `package extension`:
- `[amd64, x86_64-alpine-linux-musl]` — Alpine/musl
- `[arm64, aarch64-alpine-linux-musl]` — Alpine/musl arm64
- `[amd64, x86_64-unknown-linux-gnu]` — glibc (centos-7 image)
- `[arm64, aarch64-unknown-linux-gnu]` — glibc arm64

## What It Produces

- `.deb`, `.rpm`, `.tar.gz` for amd64 and arm64 (glibc)
- `.apk` for amd64 and arm64 (musl/Alpine)
- `dbgsym.tar.gz` — Windows debug symbols
- `dd-library-php-ssi-*-{x86_64,aarch64}-linux.tar.gz` — SSI loader packages
- `datadog-setup.php` — universal installer script
- OCI image — lib-injection image for Kubernetes auto-instrumentation

## Data Flow

```
compile tracing extension ─┐
  + link tracing extension  │
compile appsec extension  ─┤ → generate-final-artifact.sh → .tar.gz
compile appsec helper rust─┤         │
compile profiler extension─┤         v
compile loader            ─┘  nfpm → .deb/.rpm/.apk
                                     │
                     prepare-oci-package.sh → OCI image
```

Intermediate artifacts (`extensions_*/`, `appsec_*/`,
`datadog-profiling/`) feed into `generate-final-artifact.sh`, which
produces per-platform `.tar.gz` tarballs. Those are then packaged
into `.deb`/`.rpm`/`.apk` by nfpm, and into OCI images by
`prepare-oci-package.sh`. See
[building-locally.md](building-locally.md#release-package-assembly)
for prerequisites and argument details.

## Local Reproduction

These jobs assemble release packages from compiled artifacts and
rarely fail. The most common need is to inspect the generated package
structure. See
[building-locally.md](building-locally.md#release-package-assembly)
for the `generate-final-artifact.sh` and `generate-ssi-package.sh`
commands.

```bash
# Inspect what the OCI step unpacks.
# Requires packages/dd-library-php-ssi-*.tar.gz in the parent dir.
# OS and ARCH are required — the script silently exits without them.
mkdir -p oci-work && cd oci-work
OS=linux ARCH=amd64 bash ../.gitlab/prepare-oci-package.sh
ls -R sources/
cd ..
```

**Jobs defined in the one-pipeline template** (`package-oci`,
`oci-internal-publish`, `create-multiarch-lib-injection-image`,
`kubernetes-injection-test-ecr-publish`, `promote-oci-to-*`,
`publishing-gate`, `requirements_json_test`,
`validate_supported_configurations_v2_local_file`) **cannot be fully
reproduced locally** — their scripts live in a remote GitLab template
at `gitlab-templates.ddbuild.io`. Only `prepare-oci-package.sh`
(the preparation step for `package-oci`) can be tested locally as
shown above.

## Gotchas

- **`one-pipeline.locked.yml` is auto-generated.** It contains a single
  `include: remote:` pointing to `gitlab-templates.ddbuild.io`. The OCI,
  promotion, and publishing-gate jobs are defined in that remote template — their
  exact `script:` is not in this repo.

- **`requirements_json_test` always runs** (its `rules:` force `when: on_success`).
  Failures indicate a malformed JSON in `loader/packaging/`.

- **`validate_supported_configurations_v2_local_file` needs no prerequisites**
  (`needs: []`). It validates `metadata/supported-configurations.json` against the
  Datadog-wide schema in the one-pipeline template infrastructure.

- **`publish to public s3` is manual on non-master branches.** Automatic only on
  `master` non-schedule runs. Requires `prepare code`,
  `datadog-setup.php`, `package extension windows`, all
  `package extension`, and all `package loader` jobs to have
  succeeded.

- **`bundle for reliability env` only runs on nightly builds or release branches.**
  Manual with `allow_failure: true` on all other branches.

- **Compile images for glibc packages use `centos-7`**, not
  `bookworm`. See the "centos-7 vs bookworm" gotcha in
  [compile-artifacts.md](compile-artifacts.md) for details.

- **`package loader` depends on many upstream compile jobs** — appsec helper (C++
  and Rust), loader (glibc and musl), tracing extension aggregates, sidecar, all
  appsec and profiler extension versions. A single upstream failure blocks packaging.
  See [building-locally.md § SSI Loader Package Assembly](building-locally.md#ssi-loader-package-assembly)
  for local reproduction and important caveats (empty stubs do not work;
  `standalone_*/` not `extensions_*/`; must run on amd64).

- **`php: command not found` warnings in `php_fpm_packaging`.** The packaging
  image does not have `php` on PATH. Make evaluates all `$(shell ...)` variable
  definitions at parse time (lines like `PHP_EXTENSION_DIR`, `PHP_MAJOR_MINOR`,
  `ASAN`, `XDEBUG_SO_FILE`), even for targets that never use them. The packaging
  targets only use `VERSION`, `ARCHITECTURE`, and fpm/tarball logic — the
  PHP-dependent variables are irrelevant. The warnings are harmless.
