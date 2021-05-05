--TEST--
Test the output of \DDTrace\get_current_context without env variables.
--FILE--
<?php
$context = \DDTrace\get_current_context();
var_dump($context);

if (strcmp($context[0], strval(\DDTrace\trace_id())) !== 0) {
    echo "\\DDTrace\\get_current_context() doesn't match \\DDTrace\\trace_id()";
}

if (strcmp($context[1], strval(\dd_trace_peek_span_id())) !== 0) {
    echo "\\DDTrace\\get_current_context() doesn't match \dd_trace_peek_span_id()";
}

?>
--EXPECTF--
array(4) {
  [0]=>
  string(%d) "%d"
  [1]=>
  string(%d) "%d"
  [2]=>
  NULL
  [3]=>
  NULL
}
