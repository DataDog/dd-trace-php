--TEST--
FFE explicit agentless source permits CDN fetching before provider registration
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
ffeTestAgentlessActivation('explicit', 'agentless', null, true, true);
ffeTestAgentlessActivation('canonical', 'agentless', '1', true, true);
ffeTestAgentlessActivation('explicit_over_legacy', 'agentless', null, true, true, '0');
?>
--EXPECT--
explicit.before_registration=fetching
explicit.after_registration=fetching
explicit.value=blue
canonical.before_registration=fetching
canonical.after_registration=fetching
canonical.value=blue
explicit_over_legacy.before_registration=fetching
explicit_over_legacy.after_registration=fetching
explicit_over_legacy.value=blue
