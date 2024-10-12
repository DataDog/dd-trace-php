--TEST--
Empty post request
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=foo
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']);
?>
--EXPECTF--
array(3) {
  ["runtime-id"]=>
  string(36) "%s"
  ["_dd.p.dm"]=>
  string(2) "-0"
  ["_dd.p.tid"]=>
  string(16) "%s"
}
