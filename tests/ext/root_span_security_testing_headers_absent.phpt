--TEST--
Security-testing header tags are absent when headers are not sent
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$spans = dd_trace_serialize_closed_spans();
var_dump(array_key_exists('http.request.headers.x-datadog-endpoint-scan', $spans[0]['meta']));
var_dump(array_key_exists('http.request.headers.x-datadog-security-test', $spans[0]['meta']));
?>
--EXPECT--
bool(false)
bool(false)
