--TEST--
Protection of a frame being walked survives fiber suspension and out-of-order destruction
--DESCRIPTION--
A walk holds raw pointers into one per-frame allocation across a call into userland, so a join must not grow that allocation meanwhile.
Describing "currently being walked" as a stack of active walks only works while walks nest: a fiber can suspend inside a hook callback and be resumed -- or destroyed -- in any order, on a different C stack.
The flag therefore lives on the frame's own record, which has exactly the right lifetime.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) die('skip: fibers need PHP 8.1+');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target_a() { return 'a'; }
function target_b() { return 'b'; }

DDTrace\install_hook('target_a', null, function () {
    echo "  a end suspend\n";
    Fiber::suspend();
    echo "  a end resume\n";
});
DDTrace\install_hook('target_b', null, function () {
    echo "  b end suspend\n";
    Fiber::suspend();
    echo "  b end resume\n";
});

// Both fibers park inside an end callback, then finish in the order they were started -- so B's walk outlives A's and cannot be described by anything anchored to A's stack.
$a = new Fiber(function () { target_a(); });
$b = new Fiber(function () { target_b(); });
$a->start();
$b->start();
$a->resume();
unset($a);
$b->resume();
unset($b);

// A join afterwards must not traverse anything either fiber left behind.
function ordinary_target() {
    DDTrace\install_hook('ordinary_target', null, function () { echo "  ordinary end\n"; });
    return 1;
}
var_dump(ordinary_target());

echo "Done.\n";
?>
--EXPECT--
  a end suspend
  b end suspend
  a end resume
  b end resume
  ordinary end
int(1)
Done.
