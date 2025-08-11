--TEST--
Verify ips in private ranges are detected as private(Base: 100.64.0.0, Mask: 255.192.0.0)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLIENT_IP_HEADER=whatever
HTTP_WHATEVER=100.64.0.1,7.7.7.7
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
