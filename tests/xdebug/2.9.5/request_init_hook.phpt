--TEST--
The request init hook can run with Xdebug installed and xdebug.remote_enable=1
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: PHP 7.1+ required'); ?>
--INI--
xdebug.remote_enable=1
ddtrace.request_init_hook={PWD}/../fake_request_init_hook.inc
ddtrace.traced_internal_functions=array_sum
--FILE--
<?php
if (!extension_loaded('Xdebug') || version_compare(phpversion('Xdebug'), '2.9.5') < 0) die('Xdebug 2.9.5+ required');

var_dump(array_sum([1, 2, 3]));

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());

echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Request init hook loaded.
int(6)
array_sum
Done.
