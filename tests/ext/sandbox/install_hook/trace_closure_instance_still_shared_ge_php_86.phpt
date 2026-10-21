--TEST--
HOOK_INSTANCE on evaluations of a static closure that PHP 8.6 still shares
--DESCRIPTION--
Pins the cases documented on DDTrace\HOOK_INSTANCE where PHP 8.6 keeps handing out the instance-hooked
static closure: evaluations obtained before the install, and a declaration that the install cannot
locate (in a closure that is not executing, declared outside of any function). Also pins php-src#23911:
an evaluation through a second subclass is the closure cached for the first one, with its static::.
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
    DDTrace\install_hook($closure, function () use ($i) { echo "not located hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
    echo "not located iteration $i\n";
    $closure();
}

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
not located iteration 0
not located hook 0
not located iteration 1
not located hook 0
not located hook 1
second subclass: true A
