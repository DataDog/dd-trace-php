--TEST--
HOOK_INSTANCE on a static closure rebound to another scope, then on one of its own scope
--DESCRIPTION--
On PHP 8.6 the install looks up the declaration in the methods of the closure's scope. A copy rebound
to another class is not found there, and that miss is remembered: it must not stop a later install on
an evaluation of the original scope from finding the declaration, so later evaluations stay fresh.
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

class A { static function make() { return static function () { return 1; }; } }
class B {}

$rebound = Closure::bind(A::make(), null, B::class);
DDTrace\install_hook($rebound, function () { echo "rebound: traced\n"; }, null, DDTrace\HOOK_INSTANCE);

$own = A::make();
DDTrace\install_hook($own, function () { echo "own scope: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
$fresh = A::make();
echo "fresh after the install: ", var_export($fresh !== $own, true), "\n";

$fresh();
$own();
$rebound();

?>
--EXPECT--
fresh after the install: true
own scope: traced
rebound: traced
