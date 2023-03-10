--TEST--
post fields parameters should be retrieved and redacted if needed v2
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=foo,password
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
foo=bar&password=should_not_redact&username=should_redact
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.post.foo']);
var_dump($spans[0]['meta']['http.request.post.password']);
var_dump($spans[0]['meta']['http.request.post.username']);
?>
--EXPECT--
string(3) "bar"
string(17) "should_not_redact"
string(10) "<redacted>"
