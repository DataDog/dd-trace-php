--TEST--
root span with DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED (variant using SERVER_NAME)
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=1
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
METHOD=GET
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['span_kind']);
var_dump($spans[0]['attributes']['http.method']);
var_dump($spans[0]['attributes']['http.status_code']);
var_dump($spans[0]['attributes']['http.url']);
?>
--EXPECTF--
int(2)
string(3) "GET"
string(3) "200"
string(25) "http://localhost:8888/foo"
