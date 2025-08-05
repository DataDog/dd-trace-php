--TEST--
Transmit distributed header information to spans via single B3 header without sampling decision
--ENV--
HTTP_B3=12345678901234567890-12345678901234567890123456789012
DD_TRACE_PROPAGATION_STYLE_EXTRACT=B3 single header
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$root = DDTrace\start_span();
$ctx = \DDTrace\current_context();
var_dump($ctx["distributed_tracing_parent_id"]);
var_dump($ctx["trace_id"]);
var_dump($root->propagatedSamplingPriority == DD_TRACE_PRIORITY_SAMPLING_UNKNOWN);
DDTrace\close_span();

?>
--EXPECT--
string(19) "8687463697196027922"
string(23) "85968058271978839505040"
bool(true)
