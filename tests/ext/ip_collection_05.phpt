--TEST--
Client IP should be collected if ini datadog.trace.client_ip_header_disabled is set to false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REMOTE_ADDR=127.0.0.1
--INI--
datadog.trace.client_ip_header_disabled=false
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["http.client_ip"]);
?>
--EXPECTF--
string(9) "127.0.0.1"
