--TEST--
HOOK_INSTANCE on partial function applications
--DESCRIPTION--
Each evaluation of a partial function application is a fresh static closure whose declaration the
install cannot locate. The failed lookup is remembered per declaration and scope; instance hooks must
still only cover their own instance.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die('skip: partial function application is PHP 8.6+'); ?>
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

function add($a, $b) { return $a + $b; }
class Math { static function mul($a, $b) { return $a * $b; } }

function partials() { return [add(?, ?), Math::mul(?, ?)]; }

[$add0, $mul0] = partials();
[$add1, $mul1] = partials();
echo "same: ", var_export($add0 === $add1, true), " ", var_export($mul0 === $mul1, true), "\n";

foreach ([$add0, $mul0, $add1, $mul1] as $i => $partial) {
    DDTrace\install_hook($partial, function () use ($i) { echo "hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
}
echo $add0(2, 3), "\n";
echo $mul0(2, 3), "\n";
echo $add1(4, 5), "\n";
echo $mul1(4, 5), "\n";
[$add2, $mul2] = partials();
echo $add2(6, 7), " ", $mul2(6, 7), "\n";

?>
--EXPECT--
same: false false
hook 0
5
hook 1
6
hook 2
9
hook 3
20
13 42
