--TEST--
Ensure the tracer can also be enabled or disabled in fibers
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip: Fibers are a PHP 8.1+ feature"); ?>
--INI--
datadog.trace.enabled=0
--FILE--
<?php

$fiber = new Fiber(function() {
    var_dump(spl_object_id(DDTrace\active_stack()));
    ini_set("datadog.trace.enabled", "1");
    var_dump(spl_object_id(DDTrace\active_stack()));
});

$fiber->start();

var_dump(spl_object_id(DDTrace\active_stack()));

$fiber = new Fiber(function() {
    ini_set("datadog.trace.enabled", "0");
});

$fiber->start();

var_dump(spl_object_id(DDTrace\active_stack()));

?>
--EXPECT--
int(4)
int(4)
int(1)
int(1)
