--TEST--
Verify the right span tags are present when multiple XFF headers are provided.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_FORWARDED_FOR=7.7.7.7,10.0.0.1
HTTP_X_CLIENT_IP=7.7.7.7
HTTP_X_REAL_IP=7.7.7.8
HTTP_X_FORWARDED=for="foo"
HTTP_X_CLUSTER_CLIENT_IP=7.7.7.9
HTTP_FORWARDED_FOR=7.7.7.10,10.0.0.1
HTTP_FORWARDED=for="foo"
HTTP_VIA=HTTP/1.1 GWA
HTTP_TRUE_CLIENT_IP=7.7.7.11
REMOTE_ADDR=7.7.7.12
DD_TRACE_CLIENT_IP_ENABLED=true
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]['http.request.headers.x-forwarded-for']);
var_dump($span[0]["meta"]['http.request.headers.x-real-ip']);
var_dump($span[0]["meta"]['http.request.headers.x-forwarded']);
var_dump($span[0]["meta"]['http.request.headers.x-cluster-client-ip']);
var_dump($span[0]["meta"]['http.request.headers.forwarded-for']);
var_dump($span[0]["meta"]['http.request.headers.forwarded']);
var_dump($span[0]["meta"]['http.request.headers.via']);
var_dump($span[0]["meta"]['http.request.headers.true-client-ip']);
var_dump($span[0]["meta"]['_dd.multiple-ip-headers']);
var_dump(array_key_exists('http.client_ip', $span[0]["meta"]));
?>
--EXPECTF--
string(16) "7.7.7.7,10.0.0.1"
string(7) "7.7.7.8"
string(9) "for="foo""
string(7) "7.7.7.9"
string(17) "7.7.7.10,10.0.0.1"
string(9) "for="foo""
string(12) "HTTP/1.1 GWA"
string(8) "7.7.7.11"
string(100) "x-forwarded-for,x-real-ip,x-forwarded,x-cluster-client-ip,forwarded-for,forwarded,via,true-client-ip"
bool(false)
