--TEST--
Decoding nested array POST data
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=*
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
password=should_redact&foo[bar][baz]=qux&foo[baz][bar]=quz&foo[bar][password]=should_redact
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.post.password']);
var_dump($spans[0]['meta']['http.request.post.foo.bar.baz']);
var_dump($spans[0]['meta']['http.request.post.foo.baz.bar']);
var_dump($spans[0]['meta']['http.request.post.foo.bar.password']);
?>
--EXPECT--
string(10) "<redacted>"
string(3) "qux"
string(3) "quz"
string(10) "<redacted>"
