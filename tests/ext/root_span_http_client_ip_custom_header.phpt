--TEST--
Verify the client ip is added when x-forwarded-for header is present.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLIENT_IP_HEADER=whatever
HTTP_WHATEVER=10.0.0.1,7.7.7.7
DD_TRACE_CLIENT_IP_ENABLED=true
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["http.client_ip"]);
?>
--EXPECTF--
string(7) "7.7.7.7"
