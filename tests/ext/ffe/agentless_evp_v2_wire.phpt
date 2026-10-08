--TEST--
Agentless v2 prefixed relay: FFE OpenFeature evaluation counts and deduplicated exposures reach Agent EVP with privacy
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: OpenFeature requires PHP 8+');
if (!is_file(getenv('TEST_PHP_SRCDIR') . '/tests/OpenFeature/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
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
$openFeature = true;
$agentless = true;
$evpVersion = 'v2';
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
