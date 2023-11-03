--TEST--
Reset distributed tracing context on request init
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
// Initial state
var_dump(DDTrace\current_context());

// Update distributed tracing context 
DDTrace\set_distributed_tracing_context("123", "321");
var_dump(DDTrace\current_context());

// Reinitialize request to clear the distributed context state
ini_set("datadog.trace.enabled", 0);
ini_set("datadog.trace.enabled", 1);

// Reinitialized state
var_dump(DDTrace\current_context());

?>
--EXPECT--
array(5) {
  ["trace_id"]=>
  string(1) "0"
  ["span_id"]=>
  string(1) "0"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
array(6) {
  ["trace_id"]=>
  string(3) "123"
  ["span_id"]=>
  string(3) "321"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_parent_id"]=>
  string(3) "321"
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
array(5) {
  ["trace_id"]=>
  string(1) "0"
  ["span_id"]=>
  string(1) "0"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
