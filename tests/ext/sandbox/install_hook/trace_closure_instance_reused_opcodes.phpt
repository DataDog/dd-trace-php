--TEST--
HOOK_INSTANCE on a static closure whose opcodes reuse those of a freed, unlocatable declaration
--DESCRIPTION--
On PHP 8.6 the install remembers, by opcodes address, the declarations that it cannot locate. Once
such a declaration (eval'd code) is freed, a later declaration may get the same address: its install
must still find it and uncache it, so later evaluations stay fresh.
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

$unlocatable = eval('return static function () { return 1; };');
DDTrace\install_hook($unlocatable, function () {}, null, DDTrace\HOOK_INSTANCE);
unset($unlocatable);

eval('function cached() { return static function () { return 2; }; }');
$hooked = cached();
DDTrace\install_hook($hooked, function () { echo "traced\n"; }, null, DDTrace\HOOK_INSTANCE);
echo "fresh after the install: ", var_export(cached() !== $hooked, true), "\n";
cached()();
$hooked();

?>
--EXPECT--
fresh after the install: true
traced
