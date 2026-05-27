--TEST--
Security-testing headers are collected unconditionally on the root span
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HEADER_TAGS=
HTTP_X_DATADOG_ENDPOINT_SCAN=endpoint-scan-uuid
HTTP_X_DATADOG_SECURITY_TEST=security-test-uuid
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.headers.x-datadog-endpoint-scan']);
var_dump($spans[0]['meta']['http.request.headers.x-datadog-security-test']);
?>
--EXPECT--
string(18) "endpoint-scan-uuid"
string(18) "security-test-uuid"
