--TEST--
Reset distributed tracing context on request init
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

// Initial state
var_dump(DDTrace\current_context());

// Update distributed tracing context 
DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]);
var_dump(DDTrace\current_context());

// Reinitialize request to clear the distributed context state
dd_trace_internal_fn('initialize_request');

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
array(7) {
  ["trace_id"]=>
  string(3) "123"
  ["span_id"]=>
  string(3) "321"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_origin"]=>
  string(3) "foo"
  ["distributed_tracing_parent_id"]=>
  string(3) "321"
  ["distributed_tracing_propagated_tags"]=>
  array(1) {
    ["a"]=>
    string(1) "b"
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
