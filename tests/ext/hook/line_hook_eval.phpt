--TEST--
Diagnostic: eval()'d code is out of scope for line hooks and must not crash or mis-resolve
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = basename($h->file) . ':' . $h->line; };

// A hook on this very file must not be dragged into an eval'd op_array.
$id = DDTrace\install_line_hook(__FILE__, 13, $p);

eval('function from_eval($x) { return $x + 1; }');
var_dump(from_eval(1));
eval('$q = 5;');
var_dump($q);

$z = 'z';                   // 14 (13 is blank, so it slides)
echo 'own file: ', implode(',', $log), "\n";
DDTrace\remove_hook($id);

// Hooking the eval'd unit by its synthetic filename must at worst do nothing.
$log = [];
$e = DDTrace\install_line_hook("eval()'d code", 1, $p);
var_dump($e < 0);
var_dump(from_eval(2));
eval('$r = 6;');
echo 'eval name: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($e);
echo "Done.\n";
?>
--EXPECT--
int(2)
int(5)
own file: line_hook_eval.php:14
bool(true)
int(3)
eval name: (never fired)
Done.
