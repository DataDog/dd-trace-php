--TEST--
FFE canonical enablement without an explicit source remains inactive even after provider use
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: OpenFeature requires PHP 8+');
if (!is_file(getenv('TEST_PHP_SRCDIR') . '/tests/OpenFeature/vendor/autoload.php')) die('skip: OpenFeature test dependencies required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_FEATURE_FLAGS_ENABLED=0
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
require __DIR__ . '/stubs/agentless_activation.inc';
ffeTestAgentlessActivation('enabled_absent', null, '1', false, false);
ffeTestAgentlessActivation('enabled_empty', '', '1', false, false);
ffeTestAgentlessActivation('enabled_whitespace', '   ', '1', false, false);
ffeTestAgentlessActivation('enabled_over_legacy', null, '1', false, false, '0');
ffeTestAgentlessActivation('enabled_over_legacy_true', null, '1', false, false, '1');
?>
--EXPECT--
enabled_absent.before_registration=quiet
enabled_absent.after_registration=quiet
enabled_absent.value=fallback
enabled_empty.before_registration=quiet
enabled_empty.after_registration=quiet
enabled_empty.value=fallback
enabled_whitespace.before_registration=quiet
enabled_whitespace.after_registration=quiet
enabled_whitespace.value=fallback
enabled_over_legacy.before_registration=quiet
enabled_over_legacy.after_registration=quiet
enabled_over_legacy.value=fallback
enabled_over_legacy_true.before_registration=quiet
enabled_over_legacy_true.after_registration=quiet
enabled_over_legacy_true.value=fallback
