--TEST--
Add endpoints
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--FILE--
<?php
\DDTrace\add_endpoint( "/api/v1/traces", "operation_name", "resource_name", "GET","type", "body_typeaaaa", "response_type", 2, '{"some":"json"}');
--EXPECT--
ddappsec extension is available
ddappsec extension is enabled
