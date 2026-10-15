--TEST--
Transmit distributed header information to spans via single B3 header
--ENV--
HTTP_B3=42-10-d
DD_TRACE_PROPAGATION_STYLE_EXTRACT=B3 single header
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\start_span();
$ctx = \DDTrace\current_context();
var_dump($ctx["distributed_tracing_parent_id"]);
var_dump($ctx["trace_id"]);
var_dump(DDTrace\get_priority_sampling());
DDTrace\close_span();

?>
--EXPECTF--
Deprecated: Function DDTrace\current_context() is deprecated in %s on line %d
string(2) "16"
string(2) "66"
int(2)
