--TEST--
HOOK_INSTANCE caching boundaries for PHP 8.6 static closures
--DESCRIPTION--
Pins the PHP 8.6 closure caching boundaries: evaluations obtained before the install stay shared,
while indexed declarations in inactive closures and property hooks produce fresh closures after it.
Also pins php-src#23911: an evaluation through a second subclass is the closure cached for the first
one, with its static::.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die('skip: static closure caching is PHP 8.6+'); ?>
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

function cached() { return static function () { return 1; }; }

$routes = [cached(), cached()];
foreach ($routes as $i => $route) {
    DDTrace\install_hook($route, function () use ($i) { echo "route hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
}
echo "obtained before the install: ", var_export($routes[0] === $routes[1], true), "\n";
$routes[0]();
unset($routes, $route);

$make = eval('return function () { return static function () { return 1; }; };');
for ($i = 0; $i < 2; ++$i) {
    $closure = $make();
    DDTrace\install_hook($closure, function () use ($i) { echo "nested hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
    echo "nested iteration $i\n";
    $closure();
}

class Hooks { public $closure { get { return static function () { return 1; }; } } }
$hooks = new Hooks;
$hooked = $hooks->closure;
DDTrace\install_hook($hooked, function () { echo "property hook: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
echo "property hook: ", var_export($hooks->closure === $hooked, true), "\n";
($hooks->closure)();
$hooked();

class P { static function make() { return static function () { return static::class; }; } }
class A extends P {}
class B extends P {}
$a = A::make();
$b = B::make();
echo "second subclass: ", var_export($a === $b, true), " ", $b(), "\n";

?>
--EXPECT--
obtained before the install: true
route hook 0
route hook 1
nested iteration 0
nested hook 0
nested iteration 1
nested hook 1
property hook: false
property hook: traced
second subclass: true A
