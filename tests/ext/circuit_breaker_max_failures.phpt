--TEST--
Circuit breaker failures count over maximum is tripping the protection
--FILE--
<?php
function print_dd_tracer_circuit_breaker_is_closed(){
    echo (dd_tracer_circuit_breaker_info()['closed'] ? 'true' : 'false') . PHP_EOL;
}

dd_tracer_circuit_breaker_register_success();

print_dd_tracer_circuit_breaker_is_closed(); //> true
dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> true

dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> true

dd_tracer_circuit_breaker_register_error(); // 3th failure should trip the breaker
print_dd_tracer_circuit_breaker_is_closed(); //> false

// register success should reset the failure count
dd_tracer_circuit_breaker_register_success();
print_dd_tracer_circuit_breaker_is_closed(); //> true

dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> true

dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> true

dd_tracer_circuit_breaker_register_error(); // 3th failure should trip the breaker
print_dd_tracer_circuit_breaker_is_closed(); //> false

// test being able to override max consecutive failures
dd_tracer_circuit_breaker_register_success();
print_dd_tracer_circuit_breaker_is_closed(); //> true
dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> true
putenv('DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES=2');
dd_tracer_circuit_breaker_register_error();
print_dd_tracer_circuit_breaker_is_closed(); //> false

?>
--EXPECT--
true
true
true
false
true
true
true
false
true
true
false
