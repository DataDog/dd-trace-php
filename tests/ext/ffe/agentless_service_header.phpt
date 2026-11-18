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
// The header is the service of the serialized root span, normalized by libdatadog, or no
// header. Without DD_SERVICE or OTEL_SERVICE_NAME there is no header, because an
// integration can change the default service of the root span. libdatadog tests the
// non-ASCII names; on Windows they do not reach a child process unchanged.
ffeTestAgentlessServiceHeader('dd_service', 'My Service', null);
ffeTestAgentlessServiceHeader('empty_after_normalization', '___', null);
ffeTestAgentlessServiceHeader('service_mapping', 'checkout', null, 'checkout:payments');
ffeTestAgentlessServiceHeader('dd_service_over_otel', 'checkout', 'otel-checkout');
ffeTestAgentlessServiceHeader('otel_service_name', null, 'otel-checkout');
ffeTestAgentlessServiceHeader('default', null, null);
?>
--EXPECT--
dd_service=my_service span=My Service
empty_after_normalization=(none) span=___
service_mapping=payments span=payments
dd_service_over_otel=checkout span=checkout
otel_service_name=otel-checkout span=otel-checkout
default=(none) span=agentless_service_header_app.php
