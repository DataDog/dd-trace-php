--TEST--
HOOK_INSTANCE covers every evaluation of a closure shared by PHP 8.6+
--DESCRIPTION--
Since PHP 8.6, a static closure without captured state is created once per declaration site,
and a first-class callable of a function once per function, then kept until request end.
An instance hook on such a closure therefore covers every evaluation, even after all
references were dropped. Non-static and capturing closures keep per-instance hooks.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die('skip: closure caching is PHP 8.6+'); ?>
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

function cached() { return static fn($a) => $a; }
function nonStatic() { return function ($a) { return $a; }; }
function capturing($x) { return static function ($a) use ($x) { return $a; }; }
function plain($a) { return $a; }
function firstClassCallable() { return plain(...); }

foreach (['cached', 'nonStatic', 'capturing', 'firstClassCallable'] as $factory) {
    $first = $factory(0);
    $second = $factory(0);
    echo "$factory: same object: ", var_export($first === $second, true), "\n";

    DDTrace\install_hook($first, function (DDTrace\HookData $hook) use ($factory) {
        echo "$factory: traced call {$hook->args[0]}\n";
    }, null, DDTrace\HOOK_INSTANCE);

    $first(1);
    $second(2);
    unset($first, $second);
    $factory(0)(3);
}

?>
--EXPECT--
cached: same object: true
cached: traced call 1
cached: traced call 2
cached: traced call 3
nonStatic: same object: false
nonStatic: traced call 1
capturing: same object: false
capturing: traced call 1
firstClassCallable: same object: true
firstClassCallable: traced call 1
firstClassCallable: traced call 2
firstClassCallable: traced call 3
