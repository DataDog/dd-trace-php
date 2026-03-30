# CI Job Groups — Local Reproduction Guide

This directory documents how to reproduce CI failures locally for each class of CI job.
Each file covers one group of jobs that share the same runner type, Docker image, and
execution model. Where possible, it also covers how to narrow the test run and substitute
debug binaries.

## `dockerh` — Docker helper

Most groups use `.claude/ci/dockerh` to run containers. It wraps `docker run` with:

- Repo checkout mounted **read-only** at `/project/dd-trace-php`
- Writable **cache overlays** for build artifact dirs (`target/`, `profiling/target/`,
  `appsec/build/`, `tmp/`) mounted on top, so the checkout is never polluted
- `--user $(id -u):$(id -g)` so cache files are owned by the host user
- `-e HOME=/tmp` so tools that write to `~` don't fail

Cache dirs live at `~/.cache/dd-ci/<NAME>/` and persist between runs.

```
Usage: dockerh --cache NAME [--clean-cache] [--no-cache-overlay] [--writable-tree] [--php VARIANT] IMAGE [DOCKER_OPTIONS...] -- COMMAND [ARGS...]

  --cache NAME          Cache name (required); stored at ~/.cache/dd-ci/NAME/
  --clean-cache         Delete the cache dir, then continue
  --no-cache-overlay    Skip cache overlay mounts (--cache not required)
  --writable-tree       Mount the repo read-write instead of read-only
  --php VARIANT         Call switch-php VARIANT before running (nts, zts, debug, nts-asan, debug-zts-asan)
  --help                Show this help
```

Pass extra Docker options between `IMAGE` and `--`. For example, `--user root` to run
as root for `apt-get` installs.

### PHP variant selection (`--php`)

`datadog/dd-trace-ci` images ship multiple PHP builds under `/opt/php/` and a
`switch-php` command that symlinks the active build into `/usr/local/bin/`.
**The default `php` on `$PATH` is the debug build**, which rarely is the correct
default. Always pass `--php nts` (or another variant) when building or running
PHP code.

`--php VARIANT` runs the container as the `circleci` user (uid=3434, gid=3434)
who has passwordless sudo. It injects a minimal entrypoint that calls
`switch-php VARIANT` before your command, so `php` is the correct build
throughout.

**NTS vs ZTS matters for compiled extensions:** building a PHP extension with
NTS headers and loading it into a ZTS PHP (or vice versa) will crash. Always
pass `--php zts` when building or running extensions for ZTS PHP, and use a
separate `--cache` name per `(php-version, phpts, architecture)` tuple to avoid
mixing build artifacts.

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

```bash
jq -r '.mcpServers.gitlab.env.GITLAB_PERSONAL_ACCESS_TOKEN' ~/.claude.json
```

### Reading job logs

```bash
TOKEN=$(jq -r '.mcpServers.gitlab.env.GITLAB_PERSONAL_ACCESS_TOKEN' ~/.claude.json)
curl -s -H "PRIVATE-TOKEN: $TOKEN" \
  "https://gitlab.ddbuild.io/api/v4/projects/355/jobs/<JOB_ID>/trace"
```

### Monitoring a pipeline

Use `.claude/ci/check-ci` to follow a pipeline until all jobs complete.

Results are written to `/tmp/gitlab_<pipeline_id>/`:
- `success.txt` — `<job_id>\t<job_name>` per line
- `failure.txt` — same format for failed jobs
- `fail_logs/<job_id>.log` — full job trace for each failure

Exit codes: 0 = all passed, 1 = failures or threshold reached.

#### Invocation pattern

Run `check-ci` **backgrounded** via the Bash tool — no redirection needed,
the tool writes output to a file automatically. Then launch a **foreground**
Haiku agent that tails that file and reports output as it arrives.

**Step 1 — start check-ci in the background (Bash tool,
`run_in_background: true`):**

```bash
export GITLAB_PERSONAL_ACCESS_TOKEN=$(jq -r \
  '.mcpServers.gitlab.env.GITLAB_PERSONAL_ACCESS_TOKEN' ~/.claude.json)
.claude/ci/check-ci [OPTIONS]
```

Options: `--commit <ref>`, `--pipeline <id>`,
`--discovery-timeout <s>` (default 60), `--poll-interval <s>` (default 60),
`--max-failures <n>` (default 50), `--timeout <s>` (default 7200 = 2 h).

The Bash tool result includes an `output-file` path — pass that to the
Haiku agent.

**Step 2 — launch a Haiku agent IN THE FOREGROUND (not backgrounded) with
this prompt, substituting the actual output-file path:**

```
Tail the file <OUTPUT_FILE> and report its contents to the user as new lines
appear. Use the Read tool in a loop, sleeping 60 s between reads, tracking
the last offset you read so you only show new content each time.
Stop when you see a line matching "All pipelines completed" or
"Stopping script after maximum" or the file has not grown for 5 minutes.
When done, use the speak_when_done MCP tool to announce the result:
say "All CI jobs passed" if the last status line shows failed=0,
otherwise say "Some CI jobs failed".
```

---

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
