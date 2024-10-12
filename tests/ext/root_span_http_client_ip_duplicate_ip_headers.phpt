--TEST--
Verify the right span tags are present when multiple XFF headers are provided.
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_FORWARDED_FOR=7.7.7.7,10.0.0.1
HTTP_X_CLIENT_IP=7.7.7.7
HTTP_X_REAL_IP=7.7.7.8
HTTP_X_FORWARDED=for="foo"
HTTP_X_CLUSTER_CLIENT_IP=7.7.7.9
HTTP_FORWARDED_FOR=7.7.7.10,10.0.0.1
HTTP_TRUE_CLIENT_IP=7.7.7.11
REMOTE_ADDR=7.7.7.12
DD_TRACE_CLIENT_IP_ENABLED=true
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump(isset($span[0]["meta"]['http.request.headers.x-forwarded-for']));
var_dump(isset($span[0]["meta"]['http.request.headers.x-real-ip']));
var_dump(isset($span[0]["meta"]['http.request.headers.x-forwarded']));
var_dump(isset($span[0]["meta"]['http.request.headers.x-cluster-client-ip']));
var_dump(isset($span[0]["meta"]['http.request.headers.forwarded-for']));
var_dump(isset($span[0]["meta"]['http.request.headers.true-client-ip']));
var_dump($span[0]["meta"]["http.client_ip"]);
?>
--EXPECTF--
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
string(7) "7.7.7.7"
