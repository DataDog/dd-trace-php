--TEST--
HOOK_INSTANCE on a static closure whose opcodes reuse those of a freed, unlocatable declaration
--DESCRIPTION--
The eval'd file's code is freed before the install, while its returned closure stays alive. The
declaration lookup must forget the freed parent without losing the child location. Once the child
is also freed, reused opcode addresses must not interfere with later declaration lookups.
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
