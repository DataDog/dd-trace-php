# Tracer Web Framework Tests

## CI Jobs

**Source:**
- `.gitlab/generate-tracer.php` -- generates the tracer-trigger child
  pipeline; the web test jobs are generated from `TEST_WEB_{XY}` lists in
  the `Makefile`, expanded by the PHP loop starting at the
  `foreach ($jobs as $type => $type_jobs)` block
- `Makefile` -- defines per-version `TEST_WEB_{XY}` target lists (where XY is
  the PHP major+minor digits, e.g. `70` for PHP 7.0)
  and the individual `test_web_*` / `test_metrics` make targets
- `.gitlab/generate-common.php` -- shared service definitions (test-agent,
  request-replayer, httpbin-integration, mysql)

| CI Job | Image | What it does |
|--------|-------|-------------|
| `test_web_laravel_{ver}: [{php}, {sapi}]` | `datadog/dd-trace-ci:php-{php}_bookworm-6` | Laravel integration tests (4.2, 5.7, 5.8, 8.x, 9.x, 10.x, 11.x, latest, octane) |
| `test_web_symfony_{ver}: [{php}, {sapi}]` | same | Symfony integration tests (2.3--latest) |
| `test_web_wordpress_{ver}: [{php}, {sapi}]` | same | WordPress integration tests (4.8, 5.5, 5.9, 6.1) |
| `test_web_drupal_{ver}: [{php}, {sapi}]` | same | Drupal integration tests (8.9, 9.5, 10.1) |
| `test_web_magento_{ver}: [{php}, {sapi}]` | same | Magento integration tests (2.3, 2.4) |
| `test_web_slim_{ver}: [{php}, {sapi}]` | same | Slim integration tests (3.12, 4.8, latest) |
| `test_web_cakephp_{ver}: [{php}, {sapi}]` | same | CakePHP integration tests (2.8, 3.10, 4.5, latest) |
| `test_web_codeigniter_{ver}: [{php}, {sapi}]` | same | CodeIgniter integration tests (2.2, 3.1) |
| `test_web_lumen_{ver}: [{php}, {sapi}]` | same | Lumen integration tests (5.2--10.0) |
| `test_web_nette_{ver}: [{php}, {sapi}]` | same | Nette integration tests (2.4, 3.1, latest) |
| `test_web_laminas_{type}_{ver}: [{php}, {sapi}]` | same | Laminas MVC / REST integration tests |
| `test_web_yii_{ver}: [{php}, {sapi}]` | same | Yii integration tests (2.0.49, latest) |
| `test_web_zend_1: [{php}, {sapi}]` | same | Zend Framework 1 integration tests |
| `test_web_custom: [{php}, {sapi}]` | same | Custom framework integration tests |
| `test_metrics: [{php}, {sapi}]` | same | Metrics integration tests |

Runner: `arch:amd64`
Matrix: PHP 7.0+ (varies per framework) x SAPI {cli-server, cgi-fcgi, apache2handler}
(PHP >= 7.2 gets all three SAPIs; PHP 7.0--7.1 gets only a bare run without SAPI dimension.
`test_web_custom` additionally gets `fpm-fcgi`.)

Stage: `web test`

## What It Tests

These jobs test the ddtrace PHP extension's automatic instrumentation of
web frameworks. Each make target:

1. Installs composer dependencies for the framework version under
   `tests/Frameworks/<Framework>/Version_X_Y/`
2. Runs PHPUnit test suites from `tests/Integrations/<Framework>/`
3. The test harness starts a PHP web server (controlled by
   `DD_TRACE_TEST_SAPI`) and sends HTTP requests through it
4. Traces are sent to the **test-agent** service container, which validates
   them against snapshot files in `tests/snapshots/`

The `DD_TRACE_DEBUG=1` flag is always set (via `run_tests_debug`), and the
output is scanned for `[error]`, `[warning]`, or `[deprecated]` log lines
-- any such line fails the job.

## Service Containers

All web test jobs use four GitLab service containers:

| Service | Image | Alias | Port | Purpose |
|---------|-------|-------|------|---------|
| test-agent | `ddapm-test-agent:v1.22.1` | `test-agent` | 9126 | Receives traces; validates snapshots |
| request-replayer | `dd-trace-ci:php-request-replayer-*` | `request-replayer` | 80 | Replays HTTP requests for trace forwarding |
| httpbin | `kong/httpbin:0.2.2` | `httpbin-integration` | 80 | HTTP echo service for curl/guzzle tests |
| mysql | `dd-trace-ci:php-mysql-dev-5.6` | `mysql-integration` | 3306 | MySQL for WordPress, Drupal, Magento, etc. |

**All web test jobs always include the mysql service** (in addition to
test-agent, request-replayer, and httpbin) because many web frameworks use
a database. Some framework targets (e.g. `test_web_magento_*`) additionally
get `elasticsearch7` via the service-matching logic in the generator.

### Additional services by target

The generator matches substrings of the make target name against service
keys. Matches relevant to web tests:

| Target substring | Extra service | Image |
|-----------------|---------------|-------|
| `magento` | elasticsearch7 | `elasticsearch:7.17.23` (alias `elasticsearch7-integration`, port 9200) |

## Running Locally

### Prerequisites

You need Docker running. The job depends on the `compile extension: debug`
artifact (`ddtrace.so`). Locally you must build it first.

### Step 1 -- Build the extension

Use the **same cache name** as Step 3 so that `tmp/build_extension/` is shared
between the compile and test containers:

```bash
.claude/ci/dockerh --cache tracer-web-83 --overlayfs \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  -e CI_COMMIT_TAG=local \
  -- bash -c '
set -e
.gitlab/compile_extension.sh
'
```

`CI_COMMIT_TAG=local` skips the `append-build-id.sh` version-stamping step
that requires GitLab CI env vars and is not needed locally.

The compiled `ddtrace.so` will be at
`~/.cache/dd-ci/tracer-web-83/tmp/build_extension/modules/ddtrace.so`.

### Step 2 -- Start service containers

Use `.claude/ci/docker-compose.services.yml`, which mirrors the relevant
services from `docker-compose.yml` but without host port bindings. This lets
multiple PHP versions (or parallel runs) coexist on the same host — containers
communicate via Docker DNS and don't need host-side ports.

**Each concurrent test run must use a separate project name.** The project
name namespaces both containers and the Docker network. If two tests share a
project name they share the same test-agent container, and traces from
different web servers cross-contaminate each other's snapshot sessions.

#### Project naming convention

Derive the project name from the make target: strip the `test_web_` prefix,
replace underscores with hyphens, and prefix with the PHP version (no dot).
Examples:

| Make target | Project name |
|---|---|
| `test_web_laravel_latest` | `tracer-web-83-laravel-latest` |
| `test_web_symfony_latest` | `tracer-web-83-symfony-latest` |
| `test_web_nette_latest` | `tracer-web-83-nette-latest` |
| `test_web_slim_312` | `tracer-web-83-slim-312` |
| `test_metrics` | `tracer-web-83-metrics` |

Note: docker compose project names may not contain dots; use the PHP version
without a dot (e.g. `83` not `8.3`).

```bash
PROJECT=tracer-web-83-laravel-latest
docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml \
  up -d test-agent request-replayer httpbin-integration mysql-integration
```

For **magento** tests, also start:

```bash
docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml \
  up -d elasticsearch7-integration
```

The network is named `<project-name>_default` (e.g. `tracer-web-83-laravel-latest_default`).
Use that name when connecting the PHP container in Step 3.

### Step 3 -- Run tests

Connect the PHP container to the same network. The `dockerh` tool does not
manage service containers, so pass `--network ${PROJECT}_default` as an extra
Docker option. The build artifact cache (`--cache tracer-web-83`) is shared
across all targets for the same PHP version — only the service containers need
to be per-target.

`--overlayfs` is needed because composer writes to the repo tree (see
[index.md](index.md)).  Some frameworks also run database migrations as
`post-autoload-dump` scripts — these need the service containers to be up
(Step 2) before Step 3 runs.

Replace `8.3` with your PHP version and `test_web_laravel_latest` with
your target.

#### Full suite

```bash
PROJECT=tracer-web-83-laravel-latest
.claude/ci/dockerh --cache tracer-web-83 --overlayfs \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network ${PROJECT}_default \
  -e COMPOSER_MEMORY_LIMIT=-1 \
  -e DD_TRACE_ASSUME_COMPILED=1 \
  -e DDAGENT_HOSTNAME=test-agent \
  -e HTTPBIN_HOSTNAME=httpbin-integration \
  -e HTTPBIN_PORT=8080 \
  -e DATADOG_HAVE_DEV_ENV=1 \
  -e DD_TRACE_TEST_SAPI=cli-server \
  -- bash -c '
set -e
DD_TRACE_AGENT_TIMEOUT=1000 make test_web_laravel_latest RUST_DEBUG_BUILD=1
'
```

Key variables:
- `DD_TRACE_ASSUME_COMPILED=1` — tells the Makefile to skip recompiling the
  extension and use the `.so` already built in Step 1. Without this the build
  step runs again inside the test container.
- `RUST_DEBUG_BUILD=1` — builds the Rust sidecar with debug symbols, giving
  more useful backtraces. Safe to omit if you don't need Rust debug output.

#### Single test

```bash
# ... same dockerh invocation as above, but change the final make command:
DD_TRACE_AGENT_TIMEOUT=1000 make test_web_laravel_latest \
  RUST_DEBUG_BUILD=1 \
  TESTS="--filter=testScenario"
```

The `TESTS` variable is appended to the PHPUnit command. Use `--filter` to
select specific test methods.

#### SAPI variants

Set `DD_TRACE_TEST_SAPI` to control which SAPI the test harness uses:

| Value | Server type |
|-------|------------|
| `cli-server` | PHP built-in web server (default) |
| `cgi-fcgi` | PHP-CGI via nginx |
| `apache2handler` | Apache mod_php |
| `fpm-fcgi` | PHP-FPM (only `test_web_custom`) |

### Cleanup

```bash
PROJECT=tracer-web-83-laravel-latest
docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml down
```

## Gotchas

- **SNAPSHOT_CI=0 locally**: In CI, `SNAPSHOT_CI=1` makes the test-agent
  strictly validate snapshots. Locally, set it to `0` or omit it to allow
  snapshot mismatches during development. Set to `1` to reproduce exact CI
  behavior.

- **SNAPSHOT_DIR must be writable**: The test-agent reads/writes snapshots
  from `$CI_PROJECT_DIR/tests/snapshots`. `--overlayfs` handles this
  automatically.

- **Do not run multiple test targets in parallel with the same `--cache`
  name**: the overlay volume is shared, and `run_composer_with_lock` deletes
  and recreates `tests/composer.lock-php*` on each run. Concurrent containers
  race on this, causing spurious failures. Run targets sequentially or choose
  different cache names (still avoiding creating may caches, as ddtrace will
  have to be built in each one).

- **Composer version pinning**: Symfony 2.x/3.0/3.3/4.0 jobs set
  `COMPOSER_VERSION: 2.2` in CI. If you see dependency resolution failures
  on old Symfony versions, run `sudo composer self-update --2.2` inside the
  container before `composer update`.

- **mysql is always present for web tests**: Unlike integration tests where
  services are matched by target name, web tests unconditionally include
  mysql. If mysql is not running, WordPress/Drupal/Magento tests will fail
  with connection errors and other tests may emit unexpected warnings.

- **laravel_octane gets extra memory**: CI allocates 6Gi for
  `test_web_laravel_octane_latest`. If you see OOM kills locally, increase
  Docker's memory limit.

- **Services must be up before Step 3 (and stamp files must match the live
  container)**: Framework-specific composer installs run as make prerequisites
  on first test run. Several frameworks run DB setup as `post-autoload-dump`
  scripts and therefore require mysql to be reachable:
  - **Symfony Latest** — `doctrine:database:create` + `doctrine:migrations:migrate`
  - **Laravel 8x/9x/10x/11x/latest** — `artisan migrate:fresh`
  - **Drupal 9.5/10.1** — `php core/scripts/drupal install minimal`
  - **WordPress** — creates tables on first request via WP install

  Two scenarios require deleting the stamp file to force re-run:
  1. Step 3 ran while services were down — stamp was written but migrations
     were skipped.
  2. The MySQL container was recreated (e.g. you stopped and restarted
     services, or started per-target containers for the first time) — the
     stamp exists from a previous container but the new container has an empty
     database.
  Fix for both: delete `tests/Frameworks/<Framework>/composer.lock-php<ver>`
  and re-run Step 3 with services running.

- **`make composer_tests_update` is not needed**: In CI, `composer update` at
  the repo root runs before the test. Locally, each framework's dependencies
  are installed on demand as make prerequisites the first time a test target
  runs. You do not need a separate `make composer_tests_update` step.

- **Stale Symfony Messenger queue**: The `messenger_messages` table persists
  across runs. In CI, each job gets a fresh database. Locally, leftover messages
  from previous runs contaminate the queue: `messenger:consume async --limit=1`
  picks up an old message instead of the one just dispatched by the test. If
  `MessengerTest` fails with an unexpected consumer span count, truncate the
  table before re-running:
  ```bash
  docker exec tracer-web-83-symfony-latest-mysql-integration-1 \
    bash -c "mysql -utest -ptest symfonyLatest -e 'DELETE FROM messenger_messages;'"
  ```
