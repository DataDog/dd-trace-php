--TEST--
Client IP should not be collected if DD_TRACE_CLIENT_IP_ENABLED/dd_trace.client_ip_enabled is not set
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REMOTE_ADDR=127.0.0.1
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump(isset($span[0]["meta"]["http.client_ip"]));
?>
--EXPECTF--
bool(false)
