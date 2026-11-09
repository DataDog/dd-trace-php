--TEST--
Suffix matching does not spill onto a different file whose name merely ends the same way
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$a = __DIR__ . '/line_hook_basename_a.inc';
$b = __DIR__ . '/line_hook_basename_xa.inc';
require $a;
require $b;

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = basename($h->file) . ':' . $h->line; };

// Both files put their target on line 4.
$id = DDTrace\install_line_hook('line_hook_basename_a.inc', 4, $p);
var_dump(bn_a());
var_dump(bn_xa());
echo implode(',', $log), "\n";
DDTrace\remove_hook($id);

// The full relative tail must match only the intended one too.
$log = [];
$id2 = DDTrace\install_line_hook('hook/line_hook_basename_xa.inc', 4, $p);
var_dump(bn_a());
var_dump(bn_xa());
echo implode(',', $log), "\n";
DDTrace\remove_hook($id2);
echo "Done.\n";
?>
--EXPECT--
string(1) "a"
string(2) "xa"
line_hook_basename_a.inc:4
string(1) "a"
string(2) "xa"
line_hook_basename_xa.inc:4
Done.
