# Tracer Service Integration Tests

## CI Jobs

**Source:**
- `.gitlab/generate-tracer.php` -- generates the tracer-trigger child
  pipeline; integration test jobs are generated from `TEST_INTEGRATIONS_{XY}`
  lists in the `Makefile`, expanded by the PHP loop starting at the
  `foreach ($jobs as $type => $type_jobs)` block
- `Makefile` -- defines per-version `TEST_INTEGRATIONS_{XY}` (where XY is the
  PHP major+minor digits, e.g. `70` for PHP 7.0) target lists and the
  individual `test_integrations_*`, `test_opentelemetry_*`,
  `test_opentracing_*` make targets
- `.gitlab/generate-common.php` -- shared service definitions (test-agent,
  request-replayer, httpbin, mysql, redis, memcache, amqp, mongodb, kafka,
  zookeeper, elasticsearch2/7, sqlsrv, googlespanner)

| CI Job | Image | What it does |
|--------|-------|-------------|
| `test_integrations_amqp2: [{php}]` | `datadog/dd-trace-ci:php-{php}_bookworm-6` | AMQP v2 (RabbitMQ) integration tests |
| `test_integrations_amqp_latest: [{php}]` | same | AMQP latest version tests |
| `test_integrations_curl: [{php}]` | same | Curl integration tests |
| `test_integrations_deferred_loading: [{php}]` | same | Deferred loading tests (needs mysql + redis) |
| `test_integrations_elasticsearch{1,7,8,_latest}: [{php}]` | same | Elasticsearch integration tests (version-specific) |
| `test_integrations_guzzle{5,6,_latest}: [{php}]` | same | Guzzle HTTP client tests |
| `test_integrations_kafka: [{php}]` | same | Kafka integration tests (needs kafka + zookeeper) |
| `test_integrations_laminaslog2: [{php}]` | same | Laminas Log v2 tests |
| `test_integrations_memcache: [{php}]` | same | Memcache extension tests |
| `test_integrations_memcached: [{php}]` | same | Memcached extension tests |
| `test_integrations_mongodb_{1x,latest}: [{php}]` | same | MongoDB integration tests |
| `test_integrations_monolog{1,2,_latest}: [{php}]` | same | Monolog logging tests |
| `test_integrations_mysqli: [{php}]` | same | MySQLi integration tests |
| `test_integrations_openai_latest: [{php}]` | same | OpenAI integration tests |
| `test_integrations_pdo: [{php}]` | same | PDO integration tests (needs mysql) |
| `test_integrations_phpredis{3,4,5}: [{php}]` | same | PHPRedis extension tests (version-specific .so) |
| `test_integrations_predis_{1,2,latest}: [{php}]` | same | Predis library tests |
| `test_integrations_roadrunner: [{php}]` | same | RoadRunner server tests |
| `test_integrations_swoole_5: [{php}]` | same | Swoole integration tests |
| `test_integrations_frankenphp: [{php}]` | same | FrankenPHP integration tests |
| `test_integrations_ratchet: [{php}]` | same | Ratchet WebSocket tests |
| `test_integrations_pcntl: [{php}]` | same | PCNTL (process control) tests |
| `test_integrations_sqlsrv: [{php}]` | same | SQL Server integration tests |
| `test_integrations_googlespanner_latest: [{php}]` | same | Google Spanner emulator tests |
| `test_integrations_stripe_latest: [{php}]` | same | Stripe SDK tests |
| `test_opentelemetry_{1,beta}: [{php}]` | same | OpenTelemetry SDK bridge tests |
| `test_opentracing_10: [{php}]` | same | OpenTracing 1.0 bridge tests |

Runner: `arch:amd64`
Matrix: PHP 7.0+ (varies per integration; see `TEST_INTEGRATIONS_{XY}` in Makefile)

Stage: `integrations test`

## What It Tests

These jobs test the ddtrace PHP extension's automatic instrumentation of
service client libraries (databases, caches, message queues, HTTP clients,
etc.). Each make target:

1. Installs composer dependencies (if any) for the library version
2. Runs PHPUnit tests from `tests/Integrations/<Library>/`
3. Tests communicate with real service containers (MySQL, Redis, etc.)
4. Traces are sent to the **test-agent** for snapshot validation

Like web tests, `DD_TRACE_DEBUG=1` is set and output is scanned for error
log lines.

## Service Containers

All integration test jobs get the base three services (test-agent,
request-replayer, httpbin). Additional services are matched by target name
substring.

### Base services (always present)

| Service | Image | Alias | Port | Purpose |
|---------|-------|-------|------|---------|
| test-agent | `ddapm-test-agent:v1.22.1` | `test-agent` | 9126 | Receives traces; validates snapshots |
| request-replayer | `dd-trace-ci:php-request-replayer-*` | `request-replayer` | 80 | HTTP request replay |
| httpbin | `kong/httpbin:0.2.2` | `httpbin-integration` | 8080 | HTTP echo service |

### Additional services by target substring

The generator in `generate-tracer.php` matches substrings of the target
name against service keys defined in `generate-common.php`. The matching
logic is: if `str_contains($target, $part)` then include that service.

| Target substring | Service(s) added | Image | Alias | Key ports |
|-----------------|-----------------|-------|-------|-----------|
| `elasticsearch1` | elasticsearch2 | `elasticsearch:2` | `elasticsearch2-integration` | 9200 |
| `elasticsearch7` | elasticsearch7 | `elasticsearch:7.17.23` | `elasticsearch7-integration` | 9200 |
| `elasticsearch8` | elasticsearch7 | `elasticsearch:7.17.23` | `elasticsearch7-integration` | 9200 |
| `elasticsearch_latest` | elasticsearch7 | `elasticsearch:7.17.23` | `elasticsearch7-integration` | 9200 |
| `mysql` (matches `mysqli` too) | mysql | `dd-trace-ci:php-mysql-dev-5.6` | `mysql-integration` | 3306 |
| `pdo` | mysql | `dd-trace-ci:php-mysql-dev-5.6` | `mysql-integration` | 3306 |
| `deferred_loading` | mysql | `dd-trace-ci:php-mysql-dev-5.6` | `mysql-integration` | 3306 |
| `deferred_loadin` | redis | `dd-trace-ci:php-redis-5.0` | `redis-integration` | 6379 |
| `redis` (matches `phpredis`, `predis`) | redis | `dd-trace-ci:php-redis-5.0` | `redis-integration` | 6379 |
| `memcache` (matches `memcached` too) | memcache | `memcached:1.5-alpine` | `memcached-integration` | 11211 |
| `amqp` | amqp | `rabbitmq:3.9.20-alpine` | `rabbitmq-integration` | 5672 |
| `mongodb` | mongodb | `mongo:4.2.24` | `mongodb-integration` | 27017 |
| `kafka` | kafka + zookeeper | `cp-kafka:7.8.0` / `cp-zookeeper:7.8.0` | `kafka-integration` / `zookeeper` | 9092 / 2181 |
| `sqlsrv` | sqlsrv | `sqlserver:2019-CU15-ubuntu-20.04` | `sqlsrv-integration` | 1433 |
| `googlespanner` | googlespanner | `cloud-spanner-emulator/emulator:1.5.25` | `googlespanner-integration` | 9010 |

Note: `deferred_loading` matches both `deferred_loading` (mysql) and
`deferred_loadin` (redis), so it gets both mysql and redis services.

### WAIT_FOR variables

All integration test jobs inherit `WAIT_FOR: test-agent:9126` from the shared
template. The following targets **replace** (not append to) that default:

| Target | WAIT_FOR |
|--------|----------|
| `kafka` | `zookeeper:2181 kafka-integration:9092` |
| `sqlsrv` | `sqlsrv-integration:1433` |

## Running Locally

### Prerequisites

Docker running. The job depends on the `compile extension: debug` artifact.

### Step 1 -- Build the extension

Use the **same cache name** as the test step (`tracer-integ-83`) so the built
`.so` is already in place when Step 3 runs — no copy needed.
`CI_COMMIT_TAG=local` prevents `append-build-id.sh` from aborting on a missing
`CI_COMMIT_SHA`; `SHARED=1` matches the CI job variable.

```bash
.claude/ci/dockerh --cache tracer-integ-83 --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  -e CI_COMMIT_TAG=local \
  -e SHARED=1 \
  -- bash -c '
set -e
.gitlab/compile_extension.sh
'
```

The compiled extension lands at
`~/.cache/dd-ci/tracer-integ-83/tmp/build_extension/modules/ddtrace.so`.

### Step 2 -- Start service containers

Use Docker Compose with a unique project name. Multiple runs can coexist on the
same host because Compose namespaces container names under the project. Within
the network, services are reachable by their short names (`test-agent`,
`redis-integration`, etc.) via Docker DNS — no env-var changes needed in Step 3.

All services are defined in `.claude/ci/docker-compose.services.yml`. Start only
the ones your target needs:

```bash
PROJECT=tracer-integ-83   # use the same name as --cache; change to avoid conflicts
REPO=/Users/gustavo.lopes/repos/dd-trace-php

docker compose -p $PROJECT -f $REPO/.claude/ci/docker-compose.services.yml \
  up -d test-agent request-replayer httpbin-integration \
  [mysql-integration] [redis-integration] [rabbitmq-integration] \
  [memcached-integration] [elasticsearch7-integration] [mongodb-integration] \
  [kafka-integration] [sqlsrv-integration] [googlespanner-integration]
```

Quick reference — services per target substring:

| Targets | Extra services to add |
|---|---|
| mysqli, pdo, deferred_loading | `mysql-integration` |
| phpredis, predis, deferred_loading | `redis-integration` |
| amqp | `rabbitmq-integration` |
| memcache, memcached | `memcached-integration` |
| elasticsearch7, elasticsearch8, elasticsearch_latest | `elasticsearch7-integration` |
| mongodb | `mongodb-integration` |
| kafka | `kafka-integration` (brings zookeeper automatically via `depends_on`) |
| sqlsrv | `sqlsrv-integration` |
| googlespanner | `googlespanner-integration` |

### Step 3 -- Run tests

Replace `8.3` with your PHP version and `test_integrations_predis_latest`
with your target. Pass `--network ${PROJECT}_default` (matching the project
name from Step 2). The `--network` flag must appear **after** the image name.

#### Full suite

```bash
.claude/ci/dockerh --cache tracer-integ-83 --overlayfs --php debug \
  datadog/dd-trace-ci:php-8.3_bookworm-6 \
  --network ${PROJECT}_default \
  -e COMPOSER_MEMORY_LIMIT=-1 \
  -e DD_TRACE_ASSUME_COMPILED=1 \
  -e HTTPBIN_HOSTNAME=httpbin-integration \
  -e HTTPBIN_PORT=8080 \
  -e DATADOG_HAVE_DEV_ENV=1 \
  -- bash -c '
set -e
composer update --no-interaction
make composer_tests_update
DD_TRACE_AGENT_TIMEOUT=1000 make test_integrations_predis_latest RUST_DEBUG_BUILD=1
'
```

#### Single test

```bash
# Same dockerh invocation as above, but change the final make command:
DD_TRACE_AGENT_TIMEOUT=1000 make test_integrations_predis_latest \
  RUST_DEBUG_BUILD=1 \
  TESTS="--filter=testCacheHit"
```

The `TESTS` variable is appended to the PHPUnit invocation.

### Cleanup

```bash
docker compose -p $PROJECT -f .claude/ci/docker-compose.services.yml down
```

## Gotchas

- **Service matching is substring-based**: The generator uses
  `str_contains($target, $part)`. This means `phpredis` matches `redis`
  (getting the redis service), `mysqli` matches `mysql` (getting mysql),
  and `memcached` matches `memcache` (getting memcached). This is
  intentional.

- **`deferred_loading` gets two services**: It matches both
  `deferred_loading` -> mysql and `deferred_loadin` -> redis. The truncated
  key `deferred_loadin` is deliberate to match the target.

- **Kafka requires zookeeper**: The `kafka` service key is overridden in
  the generator to include both `kafka` and `zookeeper`. The Kafka container
  entrypoint waits for zookeeper to be ready before starting. CI also sets
  `CI_DEBUG_SERVICES=true` for kafka jobs and `WAIT_FOR` includes both
  `zookeeper:2181` and `kafka-integration:9092`.

- **phpredis version-specific .so files**: `test_integrations_phpredis3`
  through `phpredis5` load specific pre-built `.so` files via
  `TEST_EXTRA_INI=-d extension=redis-X.Y.Z.so`. The `bookworm-6` CI image
  only ships `redis-5.3.7.so` — **`redis-3.1.6.so` and `redis-4.3.0.so` are
  absent**, so `test_integrations_phpredis3` and `test_integrations_phpredis4`
  cannot be run locally against a PHP 8.3 image. Run them with a PHP 7.x image
  where the older `.so` files are present. `test_integrations_phpredis5`
  has a bug ([phpredis#1869](https://github.com/phpredis/phpredis/issues/1869))
  that manifests with PHP debug builds on PHP 8.0 — it is excluded from
  `TEST_INTEGRATIONS_80` for this reason. On PHP 8.1+, the target is included
  and the Makefile works around the bug via `DD_IGNORE_ARGINFO_ZPP_CHECK=1`;
  `--php debug` works for those versions.

- **googlespanner requires grpc extension**: The `test_integrations_googlespanner_latest`
  target sets `TEST_EXTRA_INI=-d extension=grpc.so` and
  `TEST_EXTRA_ENV=ZEND_DONT_UNLOAD_MODULES=1`. The grpc.so is pre-installed
  in the CI image.

- **sqlsrv requires the sqlsrv extension**: The target sets
  `TEST_EXTRA_INI=-d extension=sqlsrv.so`, pre-installed in the CI image.

- **openai_latest enables telemetry**: The `test_integrations_openai_latest`
  target temporarily sets `TELEMETRY_ENABLED=1` (normally disabled in tests).

- **No SAPI dimension**: Unlike web tests, integration tests do not vary
  by SAPI. They run only once per PHP version, using `cli-server` as the
  default `DD_TRACE_TEST_SAPI`.

- **PHP-version-limited jobs**: Several jobs are excluded from newer PHP
  versions in the CI matrix and will fail if run with the wrong PHP:
  - `elasticsearch1` — PHP 7.0–7.2 only (uses ES 1.x client requiring PHP 5/7 API)
  - `mongodb_1x` — PHP 7.0–8.0 only (`ext-mongodb 2.x` in PHP 8.1+ images is
    incompatible with `mongodb/mongodb ^1.x`)
  - `phpredis3`, `phpredis4` — PHP 7.x only (`.so` files absent from PHP 8.x images)

- **frankenphp requires ZTS**: `test_integrations_frankenphp` skips all tests
  on NTS PHP (`!ZEND_THREAD_SAFE`). Use `--php zts` and a separate ZTS cache
  (e.g. `tracer-integ-83-zts`). The CI job currently runs with the `debug`
  (NTS) variant, so all FrankenPHP tests are silently skipped in CI.

- **CI images have service clients pre-installed**: The `dd-trace-ci` PHP
  images include MySQL client (`mysqladmin`), `redis-cli`, `nc`, and other
  tools used by `.gitlab/wait-for-service-ready.sh`. If running in a
  different image, these readiness checks will fail silently.
