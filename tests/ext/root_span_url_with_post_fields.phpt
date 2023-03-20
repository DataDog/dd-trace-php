--TEST--
Post fields parameters should be retrieved and redacted if needed
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=*
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
foo=bar&password=should_redact&username=should_not_redact&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key=%7B%20%22sign%22%3A%20%22%7B0x03cb9f67%2C0xdbbc%2C0x4cb8%2C%7B0xb9%2C0x66%2C0x32%2C0x99%2C0x51%2C0xe1%2C0x09%2C0x34%7D%7D%22%7D
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['resource']);
var_dump($spans[0]['meta']['http.method']);
var_dump($spans[0]['meta']['http.request.post.foo']);
var_dump($spans[0]['meta']['http.request.post.password']);
var_dump($spans[0]['meta']['http.request.post.username']);
var_dump($spans[0]['meta']['http.request.post.token']);
var_dump($spans[0]['meta']['http.request.post.key']);
?>
--EXPECT--
string(4) "POST"
string(4) "POST"
string(3) "bar"
string(10) "<redacted>"
string(17) "should_not_redact"
string(10) "<redacted>"
string(10) "<redacted>"
