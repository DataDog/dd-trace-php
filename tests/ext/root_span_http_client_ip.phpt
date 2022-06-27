--TEST--
Verify the client ip is added from the peer IP  when no XFF header is available.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: http.client_ip only available on php 7 and 8'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REMOTE_ADDR=127.0.0.1
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["http.client_ip"]);
?>
--EXPECTF--
string(9) "127.0.0.1"
