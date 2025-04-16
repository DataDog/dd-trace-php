<?php
// In GitLab CI we use k8s and have to bind to `127.0.0.1`
$service_bind_address = "0.0.0.0";

if (getenv('GITLAB_CI') === 'true') {
   $service_bind_address = "127.0.0.1";
}

$all_minor_major_targets = [
    "7.0",
    "7.1",
    "7.2",
    "7.3",
    "7.4",
    "8.0",
    "8.1",
    "8.2",
    "8.3",
    "8.4",
];

$asan_minor_major_targets = array_filter($all_minor_major_targets, function($v) { return version_compare($v, "7.4", ">="); });

$arch_targets = ["amd64", "arm64"];

preg_match('(^\.services(.*?)\n\S)ms', file_get_contents(__FILE__), $m);
preg_match_all('(^  (\S*):)m', $m[1], $m, PREG_PATTERN_ORDER);
$services = array_combine($m[1], $m[1]);

const ASSERT_NO_MEMLEAKS = ' | tee /dev/stderr | { ! grep -qe "=== Total [0-9]+ memory leaks detected ==="; }';

function after_script($execute_dir = ".", $has_test_agent = false) {
?>

  artifacts:
    reports:
      junit: "artifacts/tests/php-tests.xml"
    paths:
      - "artifacts/"
    when: "always"
  after_script:
<?php if ($has_test_agent): ?>
    - .gitlab/check_test_agent.sh
<?php endif; ?>
    - .gitlab/check_for_core_dumps.sh "<?= $execute_dir ?>"
<?php
}

function sidecar_logs() {
?>
    _DD_DEBUG_SIDECAR_LOG_LEVEL: trace
    _DD_DEBUG_SIDECAR_LOG_METHOD: "file://${CI_PROJECT_DIR}/artifacts/sidecar.log"
<?php
}

function before_script_steps() {
?>

    # DD env vars auto-added to GitLab runners for infra purposes
    - unset DD_SERVICE
    - unset DD_ENV
    - unset DD_TAGS
    - unset DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED
    - switch-php "${SWITCH_PHP_VERSION}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}"
    - git config --global --add safe.directory "${CI_PROJECT_DIR}/*"
    - mkdir -p tmp/build_extension/modules artifacts
    - mv "modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/ddtrace.so" "tmp/build_extension/modules/"
<?php
}
?>
stages:
  - compile
  - test
  - "integrations test"
  - "web test"

variables:
  CI_DEBUG_SERVICES: "true"

.all_targets: &all_minor_major_targets
<?php
foreach ($all_minor_major_targets as $version) {
    echo "  - \"{$version}\"\n";
}
?>

.asan_targets: &asan_minor_major_targets
<?php
foreach ($asan_minor_major_targets as $version) {
    echo "  - \"{$version}\"\n";
}
?>

.arch_targets: &arch_targets
<?php
foreach ($arch_targets as $arch_target) {
    echo "- \"{$arch_target}\"\n";
}
?>

.services:
  test-agent:
    name: registry.ddbuild.io/images/mirror/dd-apm-test-agent/ddapm-test-agent:v1.21.0
    alias: test-agent
    variables:
      LOG_LEVEL: DEBUG
      TRACE_LANGUAGE: php
      DD_TRACE_AGENT_URL: http://request-replayer:80
      PORT: 9126
      SNAPSHOT_DIR: /snapshots
      SNAPSHOT_CI: 1
      DD_SUPPRESS_TRACE_PARSE_ERRORS: true
      ENABLED_CHECKS: trace_stall,trace_peer_service,trace_dd_service
      DD_POOL_TRACE_CHECK_FAILURES: true
      DD_DISABLE_ERROR_RESPONSES: true

  request-replayer:
    name: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-request-replayer-2.0
    alias: request-replayer
    command: ["php", "-S", "<?= $service_bind_address ?>:80", "index.php"]
    variables:
      DD_REQUEST_DUMPER_FILE: dump.json

  httpbin-integration:
    name: registry.ddbuild.io/images/mirror/kong/httpbin:0.2.2
    alias: httpbin-integration
    command: ["pipenv", "run", "gunicorn", "-b", "<?= $service_bind_address ?>:8080", "httpbin:app", "-k", "gevent"]

  mysql:
    name: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-mysql-dev-5.6
    alias: mysql-integration
    variables:
      MYSQL_ROOT_PASSWORD: test
      MYSQL_PASSWORD: test
      MYSQL_USER: test
      MYSQL_DATABASE: test

  elasticsearch2:
    name: registry.ddbuild.io/images/mirror/library/elasticsearch:2
    alias: elasticsearch2-integration

  elasticsearch7:
    name: registry.ddbuild.io/images/mirror/library/elasticsearch:7.17.23
    alias: elasticsearch7-integration
    variables:
      ES_JAVA_OPTS: -Xms1g -Xmx1g
      discovery.type: single-node

  zookeeper:
    name: registry.ddbuild.io/images/mirror/confluentinc/cp-zookeeper:7.8.0
    alias: zookeeper
    variables:
      ZOOKEEPER_CLIENT_PORT: 2181
      ZOOKEEPER_TICK_TIME: 2000

  kafka:
    name: registry.ddbuild.io/images/mirror/confluentinc/cp-kafka:7.8.0
    alias: kafka-integration
    variables:
      KAFKA_BROKER_ID: 111
      KAFKA_CREATE_TOPICS: test-lowlevel:1:1,test-highlevel:1:1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka-integration:9092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT
      KAFKA_INTER_BROKER_LISTENER_NAME: PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_TRANSACTION_STATE_LOG_MIN_ISR: 1
      KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR: 1
      KAFKA_AUTO_CREATE_TOPICS_ENABLE: true

  redis:
    name: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-redis-5.0
    alias: redis-integration

  memcache:
    name: registry.ddbuild.io/images/mirror/library/memcached:1.5-alpine
    alias: memcached-integration

  amqp:
    name: registry.ddbuild.io/images/mirror/rabbitmq:3.9.20-alpine
    alias: rabbitmq-integration

  mongodb:
    name: registry.ddbuild.io/images/mirror/mongo:4.2.24
    alias: mongodb-integration
    variables:
      MONGO_INITDB_ROOT_USERNAME: test
      MONGO_INITDB_ROOT_PASSWORD: test

  sqlsrv:
    name: registry.ddbuild.io/images/mirror/sqlserver:2022-latest
    alias: sqlsrv-integration
    variables:
      ACCEPT_EULA: Y
      MSSQL_SA_PASSWORD: Password12!
      MSSQL_PID: Developer

  googlespanner:
    name: registry.ddbuild.io/images/mirror/cloud-spanner-emulator/emulator:1.5.25
    alias: googlespanner-integration

<?php function agent_httpbin_service() { ?>
    - !reference [.services, test-agent]
    - !reference [.services, request-replayer]
    - !reference [.services, httpbin-integration]
<?php } ?>

"compile extension: debug":
  stage: compile
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *all_minor_major_targets
        ARCH: *arch_targets
  variables:
    host_os: linux-gnu
    SHARED: "1"
    WITH_ASAN: "0"
    CARGO_HOME: "/rust/cargo/"
    SWITCH_PHP_VERSION: "debug"
    KUBERNETES_CPU_REQUEST: 12
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 8Gi
  script: .gitlab/compile_extension.sh
  after_script: |
    export out_dir="modules/${PHP_MAJOR_MINOR}-${SWITCH_PHP_VERSION}-${host_os}-${ARCH}/"
    mkdir -p "${out_dir}"
    mv "tmp/build_extension/modules/ddtrace.so" "${out_dir}"
  cache:
    - key:
        prefix: $CI_JOB_NAME
        files:
          - Cargo.lock
          - Cargo.toml
      paths:
        - /rust/cargo/
  artifacts:
    paths:
      - "VERSION"
      - "modules/"

"compile extension: debug-zts-asan":
  extends: "compile extension: debug"
  variables:
    WITH_ASAN: "1"
    SWITCH_PHP_VERSION: "debug-zts-asan"
  parallel:
    matrix:
      - PHP_MAJOR_MINOR: *asan_minor_major_targets
        ARCH: *arch_targets

"Prepare code":
  stage: compile
  image: registry.ddbuild.io/images/mirror/php:8.2-cli
  tags: [ "arch:amd64" ]
  needs: []
  variables:
    KUBERNETES_CPU_REQUEST: 1
    KUBERNETES_MEMORY_REQUEST: 2Gi
  before_script:
    - composer update --no-interaction
  script:
    - make generate
  artifacts:
    paths:
      - src/bridge/_generated_*.php

.base_test:
  stage: test
  tags: [ "arch:${ARCH}" ]
  image: registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:php-${PHP_MAJOR_MINOR}_buster
  timeout: 20m
  variables:
    host_os: linux-gnu
    COMPOSER_MEMORY_LIMIT: "-1"
    DD_TRACE_ASSUME_COMPILED: "1"
    DDAGENT_HOSTNAME: "127.0.0.1"
    MAX_TEST_PARALLELISM: 8
    TEST_FILES_DIR: "."
    DATADOG_HAVE_DEV_ENV: 1
    HTTPBIN_HOSTNAME: httpbin-integration
    HTTPBIN_PORT: 8080
  before_script:
<?php before_script_steps() ?>
    - for host in ${WAIT_FOR:-}; do wait-for $host --timeout=30; done

.asan_test:
  extends: .base_test
  variables:
    SWITCH_PHP_VERSION: debug-zts-asan
<?php sidecar_logs(); ?>

<?php
foreach ($asan_minor_major_targets as $major_minor):
    foreach ($arch_targets as $arch):
?>
"ASAN test_c: [<?= $major_minor ?>, <?= $arch ?>]":
  extends: .asan_test
  services:
<?php agent_httpbin_service() ?>
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "<?= $arch ?>"
      artifacts: true
  variables:
    WAIT_FOR: test-agent:9126
    KUBERNETES_CPU_REQUEST: 12
    MAX_TEST_PARALLELISM: 4
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
  script:
    - make test_c
<?php after_script("tmp/build_extension", has_test_agent: true); ?>

"ASAN Internal api randomized tests: [<?= $major_minor ?>, <?= $arch ?>]":
  extends: .asan_test
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "<?= $arch ?>"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "<?= $arch ?>"
  script:
    - make test_internal_api_randomized
  after_script:
    - .gitlab/check_for_core_dumps.sh

<?php
    endforeach;
endforeach;
?>

<?php
foreach ($asan_minor_major_targets as $major_minor):
?>
"ASAN init hook tests: [<?= $major_minor ?>, amd64]":
  extends: .asan_test
  services:
    - !reference [.services, httpbin-integration]
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_with_init_hook
<?php after_script(); ?>

"ASAN Opcache tests: [<?= $major_minor ?>, amd64]":
  extends: .asan_test
  needs:
    - job: "compile extension: debug-zts-asan"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_opcache
<?php after_script(); ?>
<?php
endforeach;
?>


.debug_test:
  extends: .base_test
  variables:
    SWITCH_PHP_VERSION: debug

<?php
foreach ($all_minor_major_targets as $major_minor):
?>
"Unit tests: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_unit <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"API unit tests: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_api_unit <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"Disabled test_c run: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
<?php if (version_compare($major_minor, "7.4", ">=")): ?>
    KUBERNETES_CPU_REQUEST: 8
    MAX_TEST_PARALLELISM: 16
<?php else: /* no test parallelism */ ?>
    KUBERNETES_CPU_REQUEST: 1
  timeout: 40m
<?php endif; ?>
  script:
    - make test_c_disabled <?= ASSERT_NO_MEMLEAKS ?>
<?php after_script(); ?>

"Internal api randomized tests: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_internal_api_randomized
<?php after_script(); ?>

"Opcache tests: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
  script:
    - make test_opcache
<?php after_script("tmp/build_extension"); ?>

"PHP Language Tests: [<?= $major_minor ?>, amd64]":
  extends: .debug_test
  services:
    - !reference [.services, test-agent]
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    ARCH: "amd64"
    DD_TRACE_STARTUP_LOGS: "0"
    DD_TRACE_WARN_CALL_STACK_DEPTH: "0"
    DD_TRACE_WARN_LEGACY_DD_TRACE: "0"
    DD_TRACE_GIT_METADATA_ENABLED: "0"
    REPORT_EXIT_STATUS: "1"
    TEST_PHP_JUNIT: "/tmp/artifacts/tests/php-tests.xml"
    SKIP_ONLINE_TEST: "1"
  timeout: 40m
  script:
    - export XFAIL_LIST="dockerfiles/ci/xfail_tests/${PHP_MAJOR_MINOR}.list"
    - .gitlab/run_php_language_tests.sh
<?php after_script("/usr/local/src/php"); ?>

<?php
endforeach;
?>

.cli_integration_test:
  extends: .base_test
  variables:
    DD_TRACE_TEST_SAPI: cli-server
    COMPOSER_PROCESS_TIMEOUT: 0
    KUBERNETES_CPU_REQUEST: 2 # generally one for PHP and one for the webserver
    KUBERNETES_MEMORY_REQUEST: 4Gi
    KUBERNETES_MEMORY_LIMIT: 4Gi
    SWITCH_PHP_VERSION: debug
  before_script:
<?php before_script_steps() ?>
    - if [[ "$MAKE_TARGET" != "test_composer" ]] || ! [[ "$PHP_MAJOR_MINOR" =~ 8.[01] ]]; then sudo composer self-update --2 --no-interaction; fi
    - COMPOSER_MEMORY_LIMIT=-1 composer update --no-interaction # disable composer memory limit completely
    - make composer_tests_update
    - for host in ${WAIT_FOR:-}; do wait-for $host --timeout=30; done
  script:
    - DD_TRACE_AGENT_TIMEOUT=1000 make $MAKE_TARGET RUST_DEBUG_BUILD=1 PHPUNIT_OPTS="--log-junit artifacts/tests/results.xml" 2>&1 | tee /dev/stderr | { ! grep -qe "=== Total [0-9]+ memory leaks detected ==="; }
<?php after_script(".", true); ?>
    - find tests -type f \( -name 'phpunit_error.log' -o -name 'nginx_*.log' -o -name 'apache_*.log' -o -name 'php_fpm_*.log' -o -name 'dd_php_error.log' \) -exec cp --parents '{}' artifacts \;
    - make tested_versions && cp tests/tested_versions/tested_versions.json artifacts/tested_versions.json

.fpm_integration_test:
  extends: .cli_integration_test
  variables:
    DD_TRACE_TEST_SAPI: cgi-fcgi

.apache_integration_test:
  extends: .cli_integration_test
  variables:
    DD_TRACE_TEST_SAPI: apache2handler


<?php

// specific service maps:
$services["elasticsearch1"] = "elasticsearch2";
$services["elasticsearch_latest"] = "elasticsearch7";
$services["deferred_loading"] = "mysql";
$services["pdo"] = "mysql";

preg_match_all('(^TEST_(?<type>INTEGRATIONS|WEB)_(?<major>\d+)(?<minor>\d)[^\n]+(?<targets>.*?)^(?!\t))ms', file_get_contents(__DIR__ . "/../Makefile"), $matches, PREG_SET_ORDER);
foreach ($matches as $m):
    $major_minor = "{$m["major"]}.{$m["minor"]}";
    $type = strtolower($m["type"]);

    preg_match_all('(\t\K[a-z0-9_]+)', $m["targets"], $targets, PREG_PATTERN_ORDER);
    foreach ($targets[0] as $target):
?>
"<?= $target ?> <?= $type ?> tests: [<?= $major_minor ?>]":
  extends: .cli_integration_test
  stage: "<?= $type ?> test"
  needs:
    - job: "compile extension: debug"
      parallel:
        matrix:
          - PHP_MAJOR_MINOR: "<?= $major_minor ?>"
            ARCH: "amd64"
      artifacts: true
    - job: "Prepare code"
      artifacts: true
  services:
<?php agent_httpbin_service() ?>
<?php if ($type == "web"): ?>
    - !reference [.services, mysql]
<?php endif; ?>
<?php if ($type == "integrations"): foreach ($services as $part => $service): if (str_contains($target, $part)): ?>
    - !reference [.services, <?= $service ?>]
<?php endif; endforeach; endif; ?>
  variables:
    PHP_MAJOR_MINOR: "<?= $major_minor ?>"
    MAKE_TARGET: "<?= $target ?>"
    ARCH: "amd64"

<?php
    endforeach;
endforeach;
?>

