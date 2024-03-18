--TEST--
Test circuit breaker retrying functionality
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow'); ?>
--FILE--
<?php
function print_dd_tracer_circuit_breaker_is_closed(){
    echo (dd_tracer_circuit_breaker_info()['closed'] ? 'true' : 'false') . PHP_EOL;
}

dd_tracer_circuit_breaker_register_success();
// we should be able to immediately retry when circuit breaker was closed
echo 'closed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL;

ini_set('datadog.trace.agent_max_consecutive_failures', 1);

dd_tracer_circuit_breaker_register_error();
// circuit is tripped

// we can't retry soon after circuit has been tripped
echo 'just tripped CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL;

// set the min retry time to something very short
ini_set('datadog.trace.agent_attempt_retry_time_msec', 0);
echo 'min time set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // should be able to retry

ini_set('datadog.trace.agent_attempt_retry_time_msec', 300);
echo '0.3 seconds retry set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // 0.3 seconds not yet passed so no retry for you
usleep(350000);
echo '0.3 seconds has passed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // 0.3 seconds passed lets retry

dd_tracer_circuit_breaker_register_error();
echo '0.3 seconds has passed but new error was registered CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // start counting from beginning
usleep(350000);
echo 'another 0.3 seconds has passed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // another 0.3 seconds passed lets retry
?>
--EXPECT--
closed CAN_RETRY true
just tripped CAN_RETRY false
min time set CAN_RETRY true
0.3 seconds retry set CAN_RETRY false
0.3 seconds has passed CAN_RETRY true
0.3 seconds has passed but new error was registered CAN_RETRY false
another 0.3 seconds has passed CAN_RETRY true
