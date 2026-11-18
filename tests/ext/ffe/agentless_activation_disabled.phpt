--TEST--
FFE explicit disable prevents CDN fetching after source selection and provider registration
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
ffeTestAgentlessActivation('disabled', 'agentless', '0', false, false);
ffeTestAgentlessActivation('disabled_default', null, '0', false, false);
ffeTestAgentlessActivation('disabled_legacy', null, '0', false, false, '1');
?>
--EXPECT--
disabled.before_registration=quiet
disabled.after_registration=quiet
disabled.value=fallback
disabled_default.before_registration=quiet
disabled_default.after_registration=quiet
disabled_default.value=fallback
disabled_legacy.before_registration=quiet
disabled_legacy.after_registration=quiet
disabled_legacy.value=fallback
