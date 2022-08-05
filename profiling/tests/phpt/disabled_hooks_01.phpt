--TEST--
[profiling] test when the profiler is disabled that hooks fire normally
--DESCRIPTION--
The profiler installs some hooks even when disabled, because it cannot
generally know if it's disabled until the first request comes in. The profiler
always installs these hooks:
- `zend_execute_internal`
- `zend_interrupt_function`

The purpose of this test is to ensure that when the profiler is disabled that
regular behaviors which might use these hooks are unaffected. Note that the
PHP timeout limit is implemented using the VM interrupt handler, which is why
it is set up for this test.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_VERSION_ID < 70100)
  echo "skip: php 7.1 or above is required to execute VM interrupt hook.\n";
?>
--INI--
max_execution_time = 3
--ENV--
DD_PROFILING_ENABLED=no
--FILE--
<?php

register_shutdown_function(function () {
    echo "Shutting down.\n";
});

/* PHP optimizes some internal function calls into opcodes, such as strlen.
 * Avoid such functions in this test, since the purpose is to make sure
 * internal calls and vm interrupts are still working while the profiler is
 * disabled. For PHP 7.1 - 8.1, refer to `zend_try_compile_special_func`.
 */

while (true) {
    $cities = [
        "Boston",
        "Dublin",
        "Paris",
        "Singapore",
        "Sydney",
        "Tokyo",
    ];

    $estimated_population = [
        684379,
        544107,
        2175601,
        5917077,
        5367206,
        14043239,
    ];

    $sorted_city_population = array_combine($cities, $estimated_population);
    asort($sorted_city_population, SORT_NUMERIC);
}

?>
--EXPECTF--
Fatal error: Maximum execution time of %d seconds exceeded in %s on line %d
Shutting down.
