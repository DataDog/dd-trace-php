--TEST--
Test the output of \DDTrace\current_context
--ENV--
DD_VERSION=1.2.3
DD_ENV=dd-trace-test
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

if (strcmp($context['version'], getenv("DD_VERSION")) !== 0) {
    echo "\\DDTrace\\current_context() doesn't match DD_VERSION";
}

if (strcmp($context['env'], getenv("DD_ENV")) !== 0) {
    echo "\\DDTrace\\current_context() doesn't match DD_ENV";
}

?>
--EXPECTF--
array(5) {
  ["trace_id"]=>
  string(%d) "%d"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  string(5) "1.2.3"
  ["env"]=>
  string(13) "dd-trace-test"
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
