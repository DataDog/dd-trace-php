--TEST--
[profiling] sampling trampolines can cause crashes.
--DESCRIPTION--
The ZEND_OP_ARRAY_EXTENSION shouldn't be used if the function flags have
`ZEND_ACC_CALL_VIA_TRAMPOLINE` set because trampolines use the run_time_cache
for internal things. This very sharp edge was not documented.

See also:
https://github.com/php/php-src/commit/213248a0b91ef1a77aa91e4c91e7927328dcc839
https://github.com/DataDog/dd-trace-php/issues/1993#issuecomment-1491105610
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_MAJOR_VERSION < 8)
    echo "skip: test requires PHP 8+\n";
?>
--INI--
datadog.profiling.enabled=1
datadog.profiling.experimental_allocation_enabled=1
max_execution_time=10
--FILE--
<?php

class Magic {
    /* Only delegate to the function; any other work seems to make it take
     * longer to trigger.
     */
    public function __call($name, $args) {
        castSpell();
    }
}

// Leaving this empty increases the odds of observing the crash.
function castSpell() {}

$magic = new Magic();

for (;;) {
    /* Will eventually sigsegv, usually quickly if allocation profiling is
     * enabled because it will increase how often samples are taken.
     */
    $magic->castSpell();
}

echo "Done.\n";
?>
--EXPECTF--
Fatal error: Maximum execution time of %d seconds exceeded in %s on line %d
