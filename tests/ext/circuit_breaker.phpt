--TEST--
Test circuit_breaker functionality
--FILE--
<?php
echo (dd_tracer_circuit_breaker_is_closed() ? 'true' : 'false') . PHP_EOL;
dd_tracer_circuit_breaker_open();
echo (dd_tracer_circuit_breaker_is_closed() ? 'true' : 'false') . PHP_EOL;
dd_tracer_circuit_breaker_close();
echo (dd_tracer_circuit_breaker_is_closed() ? 'true' : 'false') . PHP_EOL;
sleep(10);


?>
--EXPECT--
true
false
true
