--TEST--
HOOK_INSTANCE on a static closure rebound to another scope, then on one of its own scope
--DESCRIPTION--
On PHP 8.6 the declaration lookup uses the closure's shared opcodes, so rebinding to another class
must still find and uncache the original declaration. Later evaluations stay fresh.
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

class A { static function make() { return static function () { return 1; }; } }
class B {}

$rebound = Closure::bind(A::make(), null, B::class);
DDTrace\install_hook($rebound, function () { echo "rebound: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
echo "fresh after rebound install: ", var_export(A::make() !== A::make(), true), "\n";

$own = A::make();
DDTrace\install_hook($own, function () { echo "own scope: traced\n"; }, null, DDTrace\HOOK_INSTANCE);
$fresh = A::make();
echo "fresh after the install: ", var_export($fresh !== $own, true), "\n";

$fresh();
$own();
$rebound();

?>
--EXPECT--
fresh after rebound install: true
fresh after the install: true
own scope: traced
rebound: traced
