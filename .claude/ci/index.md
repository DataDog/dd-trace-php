# CI Job Groups — Local Reproduction Guide

This directory documents how to reproduce CI failures locally for each class of CI job.
Each file covers one group of jobs that share the same runner type, Docker image, and
execution model. Where possible, it also covers how to narrow the test run and substitute
debug binaries.

## `dockerh` — Docker helper

Most groups use `.claude/ci/dockerh` to run containers. It wraps
`docker run` with:

- Repo checkout mounted **read-only** at `/project/dd-trace-php`
- With `--overlayfs` (recommended): a Linux overlayfs merges the
  read-only checkout (lower) with a Docker named volume
  `dd-ci-<NAME>` (upper). All writes go to the volume transparently.
- With `--php`: starts as root, sets up the host uid in
  `/etc/passwd`/sudoers, calls `switch-php`, then drops to host uid
  via `setpriv` — the user's command runs as the host user with
  full sudo access
- `-e HOME=/tmp` so tools that write to `~` don't fail

Two small static binaries are bind-mounted into the container:
- `ovl_mount` — performs the overlayfs mount after dropping to the
  host uid (retaining only the capabilities overlayfs needs)
- `setpriv` — drops privileges from root to the host uid/gid
  (replaces the system `setpriv`, which is missing or incompatible
  on Alpine/BusyBox)

Both are compiled once as static musl binaries and cached at
`~/.cache/dd-ci/`.

```
Usage: dockerh --cache NAME --overlayfs [OPTIONS] IMAGE [DOCKER_OPTIONS...] -- COMMAND [ARGS...]

  --cache NAME          Cache name (required)
  --overlayfs           Use overlayfs; writes go to Docker volume dd-ci-NAME
  --php VARIANT         Call switch-php before running
                        (nts, zts, debug, nts-asan, debug-zts-asan)
  --root                Stay as root (skip privilege drop to host uid)
  --clean-cache         Delete the cache volume, then continue
  --no-cache-overlay    Skip cache mounts (--cache not required)
  --help                Show this help
```

Pass extra Docker options between `IMAGE` and `--`.

Use `--root` for jobs that need to run as root (e.g., `apt-get
install`, writing to `/opt`). Without `--root`, dockerh drops to
the host uid/gid and makes `sudo` available (NOPASSWD).

**Cache storage:** with `--overlayfs`, writes go to Docker volume
`dd-ci-<NAME>`. To inspect: `docker run --rm -v dd-ci-NAME:/v
alpine ls /v/upper`. Use `--clean-cache` to reset. Without
`--overlayfs` (legacy mode), cache lives at `~/.cache/dd-ci/<NAME>/`
as bind-mounted directories.

To extract files from the overlay to the host (e.g., to commit
generated output), copy from the volume:
```bash
docker run --rm -v dd-ci-NAME:/v -v "$PWD:/out" alpine \
  cp /v/upper/path/to/file /out/
```

### Troubleshooting overlayfs

**Stale root-owned files in the host checkout.** The host checkout
is the overlayfs lower layer. Root-owned files left by direct
`docker run` invocations or builds outside dockerh (e.g. in `tmp/`,
`extensions_*/`, `appsec_*/`) are visible through the overlay. When
overlayfs copies them up, it preserves root ownership — the
non-root container user then gets `Permission denied`. Clean them:
```bash
# If root-owned files exist in the checkout:
docker run --rm -v "$PWD:/w" alpine \
  sh -c 'find /w -maxdepth 3 -user root \
    -not -path "/w/.git/*" -exec chown '"$(id -u):$(id -g)"' {} +'
```

**Stale overlay cache from a different PHP version.** When reusing
a `--cache` name after switching PHP versions or images, the cached
`Makefile` / `config.status` in `tmp/build_extension/` references
the old PHP include paths. Builds fail silently. Use `--clean-cache`
to reset the overlay volume when switching versions.

### PHP variant selection (`--php`)

`datadog/dd-trace-ci` images ship multiple PHP builds under `/opt/php/` and a
`switch-php` command that symlinks the active build into `/usr/local/bin/`.
**The default `php` on `$PATH` is the debug build**, which rarely is the correct
default. Always pass `--php nts` (or another variant) when building or running
PHP code. Use `--php debug` when you need debug symbols for gdb or extra
runtime assertions to diagnose failures.

`--php VARIANT` starts the container as root, registers the host uid/gid as
`localuser` in `/etc/passwd` and `/etc/sudoers`, calls `switch-php VARIANT`,
then drops to the host uid via `setpriv`. Your command runs as the host user
with passwordless sudo and `php` pointing to the selected build.

**NTS vs ZTS matters for compiled extensions:** building a PHP extension with
NTS headers and loading it into a ZTS PHP (or vice versa) will crash. Always
pass `--php zts` when building or running extensions for ZTS PHP, and use a
separate `--cache` name per `(php-version, phpts, architecture)` tuple to avoid
mixing build artifacts. Conversely, jobs that share the same tuple (e.g.
unit tests and web tests both using PHP 8.3 debug on amd64) **can** share a
`--cache` name to reuse compiled artifacts and skip redundant builds.

## Image versions

CI images are tagged `datadog/dd-trace-ci:php-{version}_bookworm-{N}` where `N`
is an iteration number shared across all GitLab appsec jobs. Find the current
value by searching for `bookworm-` in `.gitlab/generate-appsec.php`
.
The `php-8.3_bookworm-{N}` image contains: Rust (see
`profiling/rust-toolchain.toml` for the pinned version), clang-17, Go, and
multiple PHP builds under `/opt/php/` (nts, zts, debug, etc.). Use `--php nts`
(or another variant) with `dockerh` to select the right build — see the `--php`
section above.

Images referenced as `registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:TAG`
in CI scripts are mirrors of `datadog/dd-trace-ci:TAG` on Docker Hub. Pull them
directly without authentication — no registry login or image export/import needed:

```bash
docker pull datadog/dd-trace-ci:php-8.3_bookworm-6
```

(The exception is registry.ddbuild.io/images/mirror/b1o7r7e0/nginx_musl_toolchain,
which does not exist anymore in its original location)

**Other image naming patterns:**

- **centos-7 compile images:** `php-{ver}_centos-7` (e.g.
  `php-8.3_centos-7`). Used by package pipeline compile jobs for
  `x86_64-unknown-linux-gnu` / `aarch64-unknown-linux-gnu` to target
  GLIBC 2.17 for maximum compatibility.
- **Alpine compile images:** `php-compile-extension-alpine-{ver}` (e.g.
  `php-compile-extension-alpine-8.3`). No bookworm/centos suffix.
- **Appsec helper rust image:** `dd-appsec-php-ci:nginx-fpm-php-8.5-release-musl`
  (on Docker Hub, unlike the defunct C++ helper image).

**`switch-php` variant naming differs between images.** On centos-7 images,
PHP variants under `/opt/php/` are version-prefixed: `8.3`, `8.3-debug`,
`8.3-zts`. On bookworm images, variants are bare names: `nts`, `debug`,
`zts`, `nts-asan`, `debug-zts-asan`. Build scripts that call
`switch-php "${PHP_VERSION}"` (e.g. `build-tracing.sh`) work on centos but
fail on bookworm. Scripts that use bare names (e.g. `compile_extension.sh`
with `switch-php debug`) work on bookworm but not centos.

## Pipeline overview

The main `.gitlab-ci.yml` generates four child pipelines via PHP scripts:

| Pipeline | Generator | Child pipeline |
|---|---|---|
| appsec | `.gitlab/generate-appsec.php` | appsec-trigger |
| tracer | `.gitlab/generate-tracer.php` | tracer-trigger |
| profiler | `.gitlab/generate-profiler.php` | profiler-trigger |
| package/release | `.gitlab/generate-package.php` | package-trigger |
| shared | `.gitlab/generate-shared.php` | shared-trigger |

Additionally, a small number of jobs run on **GitHub Actions** (`.github/workflows/`),
not GitLab.

## GitLab access

The repo is mirrored from GitHub to GitLab at
`DataDog/apm-reliability/dd-trace-php` (project ID **355**) via gitsync.
All CI pipelines run on the GitLab mirror.

### API token

The environment variable `GITLAB_PERSONAL_ACCESS_TOKEN` should already be set.

### Reading job logs

```bash
curl -s -H "PRIVATE-TOKEN: $GITLAB_PERSONAL_ACCESS_TOKEN" \
  "https://gitlab.ddbuild.io/api/v4/projects/355/jobs/<JOB_ID>/trace"
```

### Checking CI (Gitlab)

Use `.claude/ci/check-ci` to follow a pipeline until all jobs complete.

Results are written to `/tmp/gitlab_<pipeline_id>/`:
- `success.txt` — `<job_id>\t<job_name>` per line
- `failure.txt` — same format for failed jobs
- `fail_logs/<job_id>.log` — full job trace for each failure

Exit codes: 0 = all passed, 1 = failures or threshold reached.

#### Invocation pattern

Available options: `--commit <ref>` OR `--pipeline <id>`,
`--discovery-timeout <s>` (default 60), `--poll-interval <s>` (default 60),
`--max-failures <n>` (default 50), `--timeout <s>` (default 7200 = 2 h),
`--list-jobs` (see below).

##### `--list-jobs`

Prints all jobs grouped by pipeline with their status, then exits
immediately — does not monitor or download logs. Useful for a quick
snapshot of what ran and what failed:

```bash
.claude/ci/check-ci --commit HEAD --list-jobs
```

Output format:

```
Pipeline 105413994 (status: failed):
  failed    test_extension_ci: [7.2]
  success   compile extension: debug [8.3]
  ...
```

#### Monitor CI

If --list-jobs is not passed, check-ci will run until all monitored pipelines
finish, until a timeout, or until the maximum number of failures is reached.

**Step 1 — Start check-ci in the background (Bash tool,
`run_in_background: true`):**

```bash
PYTHONUNBUFFERED=1 .claude/ci/check-ci [OPTIONS]
```

Do NOT add `&` or `mktemp` — run the command directly and let
`run_in_background: true` handle backgrounding. `PYTHONUNBUFFERED=1`
is required so Python flushes stdout into the task output file.
The Bash tool returns immediately with a line like:
```
Output is being written to: /path/to/tasks/<id>.output
```
Note that path — it is the output file for the next step.

**Step 2 — Run ci-watch in the background (Bash tool,
`run_in_background: true`):**

```bash
.claude/ci/ci-watch [--start-offset N] OUTPUT_FILE
```

`ci-watch` tails the output file and exits when there is something to
act on. Run it with `run_in_background: true` — you will be notified
when it completes. While it runs, you can do other work.

Exit codes:
- 0 — all pipelines completed (no failures)
- 1 — one or more FAILED: lines detected
- 2 — stale: no new output for 5 minutes
- 3 — check-ci timed out

On exit, ci-watch always prints `RESUME_OFFSET: <N>`. Record this
value — pass it as `--start-offset N` when re-running ci-watch to
skip already-processed content and wait for further failures.

When ci-watch completes, immediately call the `speak_when_done` MCP tool:
- "All CI jobs passed" if exit 0.
- "<N> CI jobs failed" if exit 1 (count is
  `grep "^FAILED:" OUTPUT_FILE | wc -l`).
- "CI monitor timed out" if exit 2 or 3.

**Step 3 — Act on the result**

Choose mong these actions, as appropriate:

- **Just report:** summarise the result to the user and stop.
- **Investigate failures:** read `fail_logs/<job_id>.log` under the
  output directory for each failed job and diagnose the root cause.
- **Wait for more failures:** if check-ci is still running and you want
  to keep watching after investigating, re-run ci-watch with
  `--start-offset <RESUME_OFFSET>` (back to Step 2).
- **Kill check-ci:** if you want to stop monitoring entirely, kill it
  by its task ID or PID (noted from Step 1).
- **Push fixes**: if a) the user asked you to (NOT OTHERWISE), AND b)
  you have made changes to fix the CI failures AND c) the current
  branch has an upstream branch, then commit and push. Then go back to
  Step 1. If any of the three preconditions don't match, stop and
  report the results (and your findings, if any).

### Downloading artifacts

Use `tooling/bin/download-artifacts` to download CI artifacts from GitLab jobs.

**Modes:**
- `--preset KEY` — download a well-known artifact by key (e.g., `ssi-amd64`,
  `extension-amd64-gnu`, `datadog-setup`). Use `--list-presets` to see all.
- `--job-name NAME` — download artifacts from a job matched by name (substring).
- `--job-id ID` — download artifacts directly by job ID (no pipeline needed).
- `--list-presets` — show available preset keys.

**Pipeline source** (for `--preset` and `--job-name`):
- `--pipeline ID` — use a specific pipeline.
- `--commit REF` — resolve a git ref and find its pipeline (default: HEAD).

```bash
# Download the SSI loader for amd64 from HEAD's pipeline
tooling/bin/download-artifacts --preset ssi-amd64 -o /tmp/artifacts

# Download artifacts from a specific job by name
tooling/bin/download-artifacts --job-name "compile extension: debug [8.3]" --pipeline 12345

# Download directly by job ID
tooling/bin/download-artifacts --job-id 98765 -o /tmp/artifacts
```

---

## Building artifacts locally

→ **[building-locally.md](building-locally.md)**
Consolidated reference for building each artifact locally (tracer
extension, appsec, profiler, sidecar, loader, release packages).
Covers common gotchas (CARGO_HOME, submodules, devtoolset-7,
`make` vs `make static`). Individual job group docs cross-reference
this file instead of duplicating build commands.

## Job groups

### Group A — Native Linux unit / extension / helper tests

Runner: `arch:amd64` + `arch:arm64`
Image: `datadog/dd-trace-ci:php-{version}_bookworm-6` or `datadog/dd-trace-ci:bookworm-6`
No Docker daemon — tests run directly in the container.

→ **[appsec-native-tests.md](appsec-native-tests.md)**
Covers: `test appsec extension`, `test appsec helper asan`, `appsec lint`, `appsec code coverage`

→ **[shared-zai-tea-tests.md](shared-zai-tea-tests.md)**
Covers: `Build & Test Tea`, `Extension Tea Tests`, `Zend Abstract Interface Tests`,
`ZAI Shared Tests`, `C components ASAN/UBSAN`, `Configuration Consistency`

→ **[tracer-unit-tests.md](tracer-unit-tests.md)**
Covers: `Unit tests`, `PHP Language Tests`, `test_c`, `ASAN test_c`, `Opcache tests`,
`xDebug tests`, `test_extension_ci`, `test_distributed_tracing`, `test_composer`,
`test_auto_instrumentation`, `test_integration`

---

### Group B — Native Linux web framework tests

Runner: `arch:amd64`
Image: `datadog/dd-trace-ci:php-{version}_bookworm-6`
GitLab service containers: test-agent, httpbin, request-replayer

→ **[tracer-web-tests.md](tracer-web-tests.md)**
Covers: `test_web_laravel_*`, `test_web_symfony_*`, `test_web_wordpress_*`,
`test_web_drupal_*`, `test_web_magento_*`, `test_web_slim_*`, `test_web_cakephp_*`,
`test_web_codeigniter_*`, `test_web_lumen_*`, `test_web_nette_*`,
`test_web_laminas_*`, `test_web_yii_*`, `test_web_zend_*`, `test_web_custom`,
`test_metrics`

---

### Group C — Native Linux service integration tests

Runner: `arch:amd64`
Image: `datadog/dd-trace-ci:php-{version}_bookworm-6`
GitLab service containers: MySQL, Redis, Kafka, Elasticsearch, MongoDB, etc.

→ **[tracer-integration-tests.md](tracer-integration-tests.md)**
Covers: `test_integrations_amqp*`, `test_integrations_curl`, `test_integrations_elasticsearch*`,
`test_integrations_guzzle*`, `test_integrations_kafka`, `test_integrations_memcach*`,
`test_integrations_mongodb*`, `test_integrations_monolog*`, `test_integrations_mysql*`,
`test_integrations_pdo`, `test_integrations_phpredis*`, `test_integrations_predis*`,
`test_integrations_roadrunner`, `test_integrations_swoole_5`, `test_integrations_openai_latest`,
`test_opentelemetry_*`, `test_opentracing_10`, `test_integrations_deferred_loading`,
`test_integrations_frankenphp`, `test_integrations_googlespanner_latest`,
`test_integrations_laminaslog2`, `test_integrations_pcntl`, `test_integrations_sqlsrv`,
`test_integrations_stripe_latest`

---

### Group D — Native Linux compile / artifact build

Runner: `arch:amd64` + `arch:arm64`
Image: `datadog/dd-trace-ci:php-{version}_bookworm-6`
Produces `.so` artifacts consumed by Groups B, C, H.

→ **[compile-artifacts.md](compile-artifacts.md)**
Covers: `compile extension: debug/release/zts/...` (tracer pipeline),
`compile tracing extension / sidecar / loader / asan` (package pipeline),
`compile appsec extension`, `compile appsec helper`, `compile appsec helper rust`,
`compile profiler extension`, `compile extension windows`, `link tracing extension`,
`aggregate tracing extension`, `pecl build`, `prepare code`, `cache cargo deps`

---

### Group E — Docker-in-Docker Gradle integration tests (appsec)

Runner: `docker-in-docker:amd64`
Image: `docker:24.0.4-gbi-focal`
Script: installs Java → Gradle → Gradle spins up Docker containers (PHP + helper + test-agent)

→ **[appsec-gradle-integration.md](appsec-gradle-integration.md)**
Covers: `appsec integration tests [test7.0..test8.5-*]`,
`appsec integration tests (ssi) [test8.3-release-ssi]`,
`appsec integration tests (helper-rust) [test7.4, test8.1, test8.3-debug, test8.4-zts, test8.5-musl]`,
`helper-rust build and test`, `helper-rust code coverage`, `helper-rust integration coverage`

*Note: Basic instructions are also in `appsec/helper-rust/CLAUDE.md`.*

---

### Group F — System tests

Runner: `docker-in-docker:amd64`
Image: `docker:24.0.4-gbi-focal`
Python-based `datadog/system-tests` framework; lives in `../../../system-tests/`

→ **[system-tests.md](system-tests.md)**
Covers: `System Tests: [default]`, `System Tests: [parametric]`,
`System Tests: [APPSEC_API_SECURITY*]`, `System Tests: [INTEGRATIONS]`,
`System Tests: [CROSSED_TRACING_LIBRARIES]`

→ **[system-tests-onboarding.md](system-tests-onboarding.md)**
Covers: `configure_system_tests` and onboarding/SSI scenario groups
(`simple_onboarding`, `lib-injection`, `docker-ssi`, etc.) — requires AWS
credentials; Vagrant path available but limited.

*Note: Basic instructions are also in `appsec/helper-rust/CLAUDE.md` under "System Tests".*

---

### Group G — Docker-in-Docker package verification

Runner: `docker-in-docker:amd64`
Image: `docker:24.0.4-gbi-focal`
Distinct from system tests; uses a different test harness.

→ **[package-dind-verification.md](package-dind-verification.md)**
Covers: `framework test [flow, mongodb-driver, phpredis*, wordpress]` (and `*_no_ddtrace` variants),
`installer tests`,
`randomized tests [amd64, asan/no-asan, 1..5]`

---

### Group H — Native Linux package verification (install / distribution smoke tests)

Runner: `arch:amd64` + `arch:arm64`
Various distro base images (alpine, debian, centos)
Requires packaging artifacts from Group D / Group I.

→ **[package-native-verification.md](package-native-verification.md)**
Covers: `verify alpine [*]`, `verify centos [*]`, `verify debian [*]`,
`verify .tar.gz`, `verify no json ext`, `verify windows`,
`Loader test on {amd64,arm64} {libc,alpine} [*]`,
`min install tests`, `pecl tests [*]`, `test early PHP 8.1`,
`x-profiling phpt tests on Alpine [*]`

---

### Group I — Native Linux packaging & OCI publishing

Runner: `arch:amd64`
Produces release packages and OCI images. Mostly relevant only on the release pipeline.

→ **[packaging-oci.md](packaging-oci.md)**
Covers: `package extension [*]`, `package loader [*]`, `package extension asan/windows`,
`datadog-setup.php`, `package-oci [*]`, `oci-internal-publish`,
`create-multiarch-lib-injection-image`, `kubernetes-injection-test-ecr-publish`,
`internal-publish-lib-init-tags`, `promote-oci-to-{staging,prod,prod-beta}`,
`bundle for reliability env`, `configure_system_tests`, `publishing-gate`,
`requirements_json_test`, `validate_supported_configurations_v2_local_file`,
`publish to public s3`

---

### Group J — Benchmarks

Runner: `runner:apm-k8s-tweaked-metal` / `runner:apm-k8s-same-cpu`
Dedicated performance hardware — not easily reproducible locally.

→ **[benchmarks.md](benchmarks.md)**
Covers: `benchmarks-tracer`, `benchmarks-appsec`, `benchmarks-profiler`,
`macrobenchmarks [7.4]`, `macrobenchmarks [8.1]`

---

### Group K — Windows

Runner: `windows-v2:2019`

→ **[windows-tests.md](windows-tests.md)**
Covers: `windows test_c`, `compile extension windows [*]`, `verify windows`

---

### Group L — Docker image push (manual)

Runner: `docker-in-docker:amd64/arm64`
Manual trigger only; pushes CI Docker images to ECR.

→ **[docker-image-push.md](docker-image-push.md)** *(not yet written)*
Covers: `push appsec images [amd64/arm64]`, `push appsec docker images multiarch`



---

### Group M — Lightweight utility / gate jobs

Runner: `arch:amd64` | Minimal image, quick scripts — usually not the source of failures.

Covers: `check libxml2 version`, `aggregate tested versions`,
`check-big-regressions`, `check-slo-breaches`, `notify-slo-breaches`, `finished`

---

### Group N — GitHub Actions workflows

Runs on GitHub-hosted `ubuntu-24.04` runners, not GitLab. Triggered on `pull_request`
and `schedule`. Completely separate CI system.

→ **[github-actions-profiler.md](github-actions-profiler.md)**
Covers: `Profiling correctness / prof-correctness [{8.0..8.5}, {nts,zts}]`,
`Profiling ASAN Tests / prof-asan [{8.3..8.5}, {arm64,amd64}]`

→ **[github-actions-other.md](github-actions-other.md)**
Covers: `auto_check_snapshots`, `auto_label_prs`, `auto_add_pr_to_miletone`,
`add-asset-to-gh-release`, `update_latest_versions`

→ **[github-actions-other.md](github-actions-other.md)** *(not yet written)*
Covers: `prof_asan`, `auto_check_snapshots`, `auto_label_prs`,
`auto_add_pr_to_miletone`, `add-asset-to-gh-release`, `update_latest_versions`

## Improving job details

See [meta-improv-instr.md](meta-improv-instr.md) for how to run the improvement
loop. In any case, should you find out-of-date or otherwise wrong information
in the job details files, or if you find undocumented but surprising details,
suggest to the user improvements to the files. Never apply those changes
without consulting the user.

The common format of job details file is described in
[meta-job-group-doc.md](meta-job-group-doc.md).
