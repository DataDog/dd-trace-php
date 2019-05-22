--TEST--
Correctness of circuit breaker info
--FILE--
<?php
dd_tracer_circuit_breaker_register_success();
var_dump(dd_tracer_circuit_breaker_info());

?>
--EXPECTF--
array(5) {
  ["closed"]=>
  bool(true)
  ["total_failures"]=>
  int(%d)
  ["consecutive_failures"]=>
  int(0)
  ["opened_timestamp"]=>
  int(%d)
  ["last_failure_timestamp"]=>
  int(%d)
}
