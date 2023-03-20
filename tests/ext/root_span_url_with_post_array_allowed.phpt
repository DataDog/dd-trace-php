--TEST--
Values of an array are not redacted when the array base is in the DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED env var
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=foo
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
foo[baz]=bar&foo[bar][key]=baz&foo[bar][baz]=quz
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.post.foo.baz']);
var_dump($spans[0]['meta']['http.request.post.foo.bar.key']);
var_dump($spans[0]['meta']['http.request.post.foo.bar.baz']);
?>
--EXPECT--
string(3) "bar"
string(3) "baz"
string(3) "quz"
