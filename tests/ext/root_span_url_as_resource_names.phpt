--TEST--
root span with DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=1
HTTPS=on
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
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
var_dump($spans[0]['meta']);
?>
--EXPECTF--
array(4) {
  ["system.pid"]=>
  %s
  ["http.method"]=>
  string(3) "GET"
  ["http.url"]=>
  string(26) "https://localhost:9999/foo"
  ["http.status_code"]=>
  string(3) "200"
}
