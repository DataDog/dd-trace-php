--TEST--
Only param whose name is in DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED shouldn't be redacted
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=foo.bar
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
username=should_redact&foo[bar]=should_not_redact&foo[baz]=should_redact&bar[foo]=should_redact
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.post.username']);
var_dump($spans[0]['meta']['http.request.post.foo.bar']);
var_dump($spans[0]['meta']['http.request.post.foo.baz']);
var_dump($spans[0]['meta']['http.request.post.bar.foo']);
?>
--EXPECT--
string(10) "<redacted>"
string(17) "should_not_redact"
string(10) "<redacted>"
string(10) "<redacted>"
