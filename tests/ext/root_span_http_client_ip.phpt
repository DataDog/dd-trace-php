--TEST--
Verify the client ip is added from the peer IP  when no XFF header is available.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REMOTE_ADDR=127.0.0.1
DD_TRACE_CLIENT_IP_ENABLED=true
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["http.client_ip"]);
?>
--EXPECTF--
string(9) "127.0.0.1"
