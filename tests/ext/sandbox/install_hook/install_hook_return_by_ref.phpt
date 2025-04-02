--TEST--
Test install_hook() on functions returning by reference
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip: Typed properties were added on PHP 7.4'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

class A {
    public static ?int $var = 1;
}

function &ref(): int {
    return A::$var;
}

$firstHook = DDTrace\install_hook("ref", null, function($hook) {
    $hook->returned++;
});
var_dump(ref());
var_dump(A::$var);

$hook = DDTrace\install_hook("ref", null, function($hook) {
    $hook->returned = "invalid";
});
var_dump(ref());
var_dump(A::$var);
DDTrace\remove_hook($hook);

DDTrace\remove_hook($firstHook);

DDTrace\install_hook("ref", null, function($hook) {
    $hook->returned = null;
});
var_dump(ref());
var_dump(A::$var);

?>
--EXPECTF--
int(2)
int(2)
[ddtrace] [warning] TypeError thrown in ddtrace's closure defined at %s:%d for ref(): Cannot assign string to reference held by property A::$var of type ?int in %s on line %d
int(3)
int(3)
NULL
NULL
[ddtrace] [info] No finished traces to be sent to the agent
