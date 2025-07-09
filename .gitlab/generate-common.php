<?php

$arch_targets = ["amd64", "arm64"];

$php_versions_to_abi = [
    "7.0" => "20151012",
    "7.1" => "20160303",
    "7.2" => "20170718",
    "7.3" => "20180731",
    "7.4" => "20190902",
    "8.0" => "20200930",
    "8.1" => "20210902",
    "8.2" => "20220829",
    "8.3" => "20230831",
    "8.4" => "20240924",
];

$all_minor_major_targets = array_keys($php_versions_to_abi);

$asan_minor_major_targets = array_values(array_filter($all_minor_major_targets, function($v) { return version_compare($v, "7.4", ">="); }));
$windows_minor_major_targets = array_values(array_filter($all_minor_major_targets, function($v) { return version_compare($v, "7.2", ">="); }));
$profiler_minor_major_targets = array_values(array_filter($all_minor_major_targets, function($v) { return version_compare($v, "7.1", ">="); }));

// In GitLab CI we use k8s and have to bind to `127.0.0.1`
$service_bind_address = "0.0.0.0";

if (getenv('GITLAB_CI') === 'true') {
   $service_bind_address = "127.0.0.1";
}

function unset_dd_runner_env_vars() {
?>

    # DD env vars auto-added to GitLab runners for infra purposes
    - unset DD_SERVICE
    - unset DD_ENV
    - unset DD_TAGS
    - unset DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED
<?php
}

?>
default:
  retry:
    max: 2
    when:
      - unknown_failure
      - data_integrity_failure
      - runner_system_failure
      - scheduler_failure
      - api_failure

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

.no_asan_targets: &no_asan_minor_major_targets
<?php
foreach (array_diff($all_minor_major_targets, $asan_minor_major_targets) as $version) {
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
    name: registry.ddbuild.io/images/mirror/dd-apm-test-agent/ddapm-test-agent:v1.22.1
    alias: test-agent
    variables:
      LOG_LEVEL: DEBUG
      TRACE_LANGUAGE: php
      DD_TRACE_AGENT_URL: http://request-replayer:80
      PORT: 9126
      SNAPSHOT_DIR: ${CI_PROJECT_DIR}/tests/snapshots
      SNAPSHOT_CI: 1
      DD_SUPPRESS_TRACE_PARSE_ERRORS: true
      ENABLED_CHECKS: trace_stall,trace_peer_service,trace_dd_service
      DD_POOL_TRACE_CHECK_FAILURES: true
      DD_DISABLE_ERROR_RESPONSES: true
      SNAPSHOT_REGEX_PLACEHOLDERS: 'path:/\S+/dd-trace-php(?=/),httpbin:(?<=//)httpbin-integration:8080'

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

  kafka-service:
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
    variables:
      DOCKER_IP: "<?= $service_bind_address ?>"

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
