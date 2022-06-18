--TEST--
Transmit distributed header information to spans via B3 headers
--ENV--
HTTP_X_B3_TRACEID=42
HTTP_X_B3_SPANID=10
HTTP_X_B3_SAMPLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_PROPAGATION_STYLE_EXTRACT=B3
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
string(2) "16"
string(2) "66"
int(1)
