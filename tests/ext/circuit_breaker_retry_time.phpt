--TEST--
Test circuit breaker retrying functionality
--FILE--
<?php
function print_dd_tracer_circuit_breaker_is_closed(){
    echo (dd_tracer_circuit_breaker_info()['closed'] ? 'true' : 'false') . PHP_EOL;
}

dd_tracer_circuit_breaker_register_success();
// we should be able to immediately retry when circuit breaker was closed
echo 'closed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL;

putenv('DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES=1');
dd_tracer_circuit_breaker_register_error();
// circuit is tripped

// we can't retry soon after circuit has been tripped
echo 'just tripped CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL;

// set the min retry time to something very short
putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=0');
echo 'min time set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // should be able to retry

// with garbage retry time
putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=garbage0');
echo 'garbage time set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // 5000msec default gets reinstated

// we can shorten the time again
putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=0');
echo 'min time set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // should be able to retry

putenv('DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC=300');
echo '0.3 seconds retry set CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // 0.3 seconds not yet passed so no retry for you
usleep(300000);
echo '0.3 seconds has passed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // 0.3 seconds passed lets retry

dd_tracer_circuit_breaker_register_error();
echo '0.3 seconds has passed but new error was registered CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // start counting from beginning
usleep(300000);
echo 'another 0.3 seconds has passed CAN_RETRY ' . (dd_tracer_circuit_breaker_can_try() ? 'true' : 'false') . PHP_EOL; // another 0.3 seconds passed lets retry
?>
--EXPECT--
closed CAN_RETRY true
just tripped CAN_RETRY false
min time set CAN_RETRY true
garbage time set CAN_RETRY false
min time set CAN_RETRY true
0.3 seconds retry set CAN_RETRY false
0.3 seconds has passed CAN_RETRY true
0.3 seconds has passed but new error was registered CAN_RETRY false
another 0.3 seconds has passed CAN_RETRY true
