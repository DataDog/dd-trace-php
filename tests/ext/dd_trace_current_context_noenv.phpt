--TEST--
Test the output of \DDTrace\current_context without env variables.
--FILE--
<?php
$context = \DDTrace\current_context();
var_dump($context);

if (strcmp($context['trace_id'], strval(\DDTrace\trace_id())) !== 0) {
    echo "\\DDTrace\\current_context() doesn't match \\DDTrace\\trace_id()";
}

if (strcmp($context['span_id'], strval(\dd_trace_peek_span_id())) !== 0) {
    echo "\\DDTrace\\current_context() doesn't match \dd_trace_peek_span_id()";
}

?>
--EXPECTF--
array(5) {
  ["trace_id"]=>
  string(%d) "%d"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
