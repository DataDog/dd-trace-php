--TEST--
FFE implicit agentless source stays inactive through provider registration and evaluation
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
// Registering a provider does not opt in to CDN requests without a setting.
ffeTestAgentlessActivation('absent', null, null, false, false);
ffeTestAgentlessActivation('empty', '', null, false, false);
ffeTestAgentlessActivation('whitespace', '   ', null, false, false);
?>
--EXPECT--
absent.before_registration=quiet
absent.after_registration=quiet
absent.value=fallback
empty.before_registration=quiet
empty.after_registration=quiet
empty.value=fallback
whitespace.before_registration=quiet
whitespace.after_registration=quiet
whitespace.value=fallback
