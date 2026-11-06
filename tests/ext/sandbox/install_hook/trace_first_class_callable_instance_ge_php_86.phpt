--TEST--
HOOK_INSTANCE on a first-class callable shared by PHP 8.6+
--DESCRIPTION--
Since PHP 8.6, a first-class callable of a function like plain(...) is one object per function until
request end, borrowed by every call site. It cannot be unshared safely, so an instance hook on it
covers every first-class callable of that function, but not direct calls.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80600) die('skip: first-class callable caching is PHP 8.6+'); ?>
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

function plain($a) { return $a; }
function fcc() { return plain(...); }

$first = fcc();
DDTrace\install_hook($first, function (DDTrace\HookData $hook) {
    echo "traced call {$hook->args[0]}\n";
}, null, DDTrace\HOOK_INSTANCE);
$second = fcc();
echo "same object: ", var_export($first === $second, true), "\n";

$first(1);
$second(2);
unset($first, $second);
fcc()(3);
plain(4);

?>
--EXPECT--
same object: true
traced call 1
traced call 2
traced call 3
