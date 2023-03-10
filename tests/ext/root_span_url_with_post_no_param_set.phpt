--TEST--
Empty post request without whitelisting
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
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
--EXPECT--
array(1) {
  ["_dd.p.dm"]=>
  string(2) "-1"
}
