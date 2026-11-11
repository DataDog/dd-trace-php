--TEST--
HOOK_INSTANCE only covers the hooked instance of a static closure
--DESCRIPTION--
PHP 8.6 shares one object between evaluations of a static closure without captured state declared
in a function. An instance hook must neither cover later evaluations nor stack across installs.
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

class Scope {}

function cached() { return static function ($a) { return $a; }; }
function nonStatic() { return function ($a) { return $a; }; }
function capturing($x) { return static function ($a) use ($x) { return $a; }; }
// Declared in a closure with its own (heap) run-time cache
$bound = Closure::bind(function () { return static function ($a) { return $a; }; }, null, 'Scope');
function inBound() { global $bound; return $bound(); }

foreach (['cached', 'nonStatic', 'capturing', 'inBound'] as $factory) {
    $first = $factory(0);
    DDTrace\install_hook($first, function (DDTrace\HookData $hook) use ($factory) {
        echo "$factory: traced call {$hook->args[0]}\n";
    }, null, DDTrace\HOOK_INSTANCE);
    $second = $factory(0);

    $first(1);
    $second(2);
    unset($first, $second);
    $factory(0)(3);
}

// The run-time cache holding the closure is freed before the install
$outer = Closure::bind(function () { return static function () { return "inner"; }; }, null, 'Scope');
$inner = $outer();
unset($outer);
DDTrace\install_hook($inner, function () { echo "dead declaring closure: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
$inner();
unset($inner);

for ($i = 0; $i < 3; ++$i) {
    $closure = cached();
    DDTrace\install_hook($closure, function () use ($i) {
        echo "loop hook $i\n";
    }, null, DDTrace\HOOK_INSTANCE);
    $closure($i);
}

// Declared in a closure run by Closure::call(), and in a non-static hook callback on a method
(function () {
    for ($i = 0; $i < 2; ++$i) {
        $closure = static function () { return 1; };
        DDTrace\install_hook($closure, function () use ($i) { echo "call() hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
        $closure();
    }
})->call(new Scope);

class Hooked { function method() {} }
DDTrace\install_hook('Hooked::method', function (DDTrace\HookData $hook) {
    for ($i = 0; $i < 2; ++$i) {
        $closure = static function () { return 1; };
        DDTrace\install_hook($closure, function () use ($i) { echo "callback hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
        $closure();
    }
});
(new Hooked)->method();

// Declared in a parent method, evaluated through two subclasses: PHP 8.6.0 hands B the closure cached
// for A (php-src#23911). Its fix changes that path, so B::make() must still be fresh after the install.
class P { static function make() { return static function () { return static::class; }; } }
class A extends P {}
class B extends P {}
$a = A::make();
$b = B::make();
DDTrace\install_hook($b, function () { echo "subclass: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
$b();
$fresh = B::make();
echo "subclass: ", $fresh(), "\n";

?>
--EXPECT--
cached: traced call 1
nonStatic: traced call 1
capturing: traced call 1
inBound: traced call 1
dead declaring closure: traced
loop hook 0
loop hook 1
loop hook 2
call() hook 0
call() hook 1
callback hook 0
callback hook 1
subclass: traced
subclass: B
