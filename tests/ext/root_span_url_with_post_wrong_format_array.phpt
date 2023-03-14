--TEST--
post fields with a deprecated way of posting an array
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=a
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
a[]=1
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.post.a']);
?>
--EXPECT--
string(1) "1"
