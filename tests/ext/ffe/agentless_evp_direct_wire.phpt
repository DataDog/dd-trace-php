--TEST--
Agentless canonical direct HTTPS: FFE native evaluation counts and deduplicated exposures reach canonical direct intake with privacy
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (PHP_OS_FAMILY !== 'Linux') die('skip: fixture uses Linux SSL_CERT_FILE support');
if (!extension_loaded('openssl')) die('skip: OpenSSL required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
_DD_DEBUG_SIDECAR_IPC_MODE=instance_per_process
DD_SITE=example.invalid
NO_PROXY=127.0.0.1,localhost
DD_FEATURE_FLAGS_ENABLED=1
DD_FEATURE_FLAGS_CONFIGURATION_SOURCE=agentless
DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=200
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_SERVICE=php-ffe-wire
DD_ENV=test
DD_VERSION=wire-version
DD_API_KEY=local-test-key-must-not-reach-agent
--FILE--
<?php
$openFeature = false;
$agentless = true;
$direct = true;
$evpVersion = 'v4';
require __DIR__ . '/stubs/flag_evaluation_wire.inc';
?>
--EXPECT--
service=php-ffe-wire
identity=bool(true)
exposures=int(2)
consented_count=int(2)
consented_subject=string(7) "subject"
consented_context=array(1) {
  ["plan"]=>
  string(3) "pro"
}
protected_count=int(1)
protected_subject=string(71) "sha256_a9491f4c1bf7b0cffbadcba2db8f028e4b3f2867cb59e1f3a0bc1968f3c51242"
protected_context_absent=bool(true)
