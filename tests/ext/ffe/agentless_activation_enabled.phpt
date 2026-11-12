--TEST--
FFE explicit enablement starts CDN fetching with an implicit agentless source
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
ffeTestAgentlessActivation('enabled_absent', null, '1', true, true);
ffeTestAgentlessActivation('enabled_empty', '', '1', true, true);
ffeTestAgentlessActivation('enabled_whitespace', '   ', '1', true, true);
ffeTestAgentlessActivation('enabled_over_legacy', null, '1', true, true, '0');
?>
--EXPECT--
enabled_absent.before_registration=fetching
enabled_absent.after_registration=fetching
enabled_absent.value=blue
enabled_empty.before_registration=fetching
enabled_empty.after_registration=fetching
enabled_empty.value=blue
enabled_whitespace.before_registration=fetching
enabled_whitespace.after_registration=fetching
enabled_whitespace.value=blue
enabled_over_legacy.before_registration=fetching
enabled_over_legacy.after_registration=fetching
enabled_over_legacy.value=blue
