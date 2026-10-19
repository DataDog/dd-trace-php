--TEST--
FFE OpenFeature evaluation counts and deduplicated exposures reach Agent EVP with privacy
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: OpenFeature requires PHP 8+');
if (!is_file(getenv('TEST_PHP_SRCDIR') . '/tests/OpenFeature/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
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
protected_subject=string(71) "sha256_2234a4963a43ffe952df4e09b1fd0d36f8b49216e5cba4182ae4d01e67cf112a"
protected_context_absent=bool(true)
protected_raw_bytes_absent=bool(true)
evaluation_times_preserved=bool(true)
identity_omissions_preserve_evaluations=bool(true)
first_256_inspected=bool(true)
unicode_and_scalar_boundaries=bool(true)
owned_snapshot=bool(true)
invalid_context_keeps_observation=bool(true)
recovered_connection_preserves_privacy=bool(true)
consented_identity_and_safe_error=bool(true)
terminal_hook_errors=bool(true)
diagnostics_exercised=bool(true)
diagnostics_pii_absent=bool(true)
