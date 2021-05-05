--TEST--
Test the output of \DDTrace\get_current_context
--ENV--
DD_VERSION=1.2.3
DD_ENV=dd-trace-test
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

if (strcmp($context[2], $_ENV["DD_VERSION"]) !== 0) {
    echo "\\DDTrace\\get_current_context() doesn't match \$_ENV['DD_VERSION']";
}

if (strcmp($context[3], $_ENV["DD_ENV"]) !== 0) {
    echo "\\DDTrace\\get_current_context() doesn't match \$_ENV['DD_ENV']";
}

?>
--EXPECTF--
array(4) {
  [0]=>
  string(%d) "%d"
  [1]=>
  string(%d) "%d"
  [2]=>
  string(5) "1.2.3"
  [3]=>
  string(13) "dd-trace-test"
}
