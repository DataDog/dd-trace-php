--TEST--
FFE agentless CDN requests send the service of the root spans in DD-Service
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
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
require __DIR__ . '/stubs/agentless_service_header.inc';
// libdatadog sends the name that APM shows, and no header if that name is not printable ASCII.
ffeTestAgentlessServiceHeader('dd_service', 'My Service', null);
ffeTestAgentlessServiceHeader('leading_space', ' svc', null);
ffeTestAgentlessServiceHeader('not_ascii', 'café-api', null);
ffeTestAgentlessServiceHeader('not_ascii_cjk', '支付', null);
ffeTestAgentlessServiceHeader('agent_fallback', '___', null);
ffeTestAgentlessServiceHeader('dd_service_over_otel', 'checkout', 'otel-checkout');
ffeTestAgentlessServiceHeader('otel_service_name', null, 'otel-checkout');
// Without a configured service, root spans use the script name.
ffeTestAgentlessServiceHeader('default', null, null);
?>
--EXPECT--
dd_service=my_service
leading_space=_svc
not_ascii=(none)
not_ascii_cjk=(none)
agent_fallback=(none)
dd_service_over_otel=checkout
otel_service_name=otel-checkout
default=agentless_service_header_app.php
