--TEST--
root span with DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=1
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=""
HTTPS=on
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?with_to_be_stripped?query_string
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
array(6) {
  ["runtime-id"]=>
  string(36) "%s"
  ["http.url"]=>
  string(26) "https://localhost:9999/foo"
  ["http.method"]=>
  string(3) "GET"
  ["_dd.p.dm"]=>
  string(2) "-0"
  ["http.status_code"]=>
  string(3) "200"
  ["_dd.p.tid"]=>
  string(16) "%s"
}
