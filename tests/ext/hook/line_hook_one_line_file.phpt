--TEST--
A whole file on one line arms every op_array that carries it
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$inc = __DIR__ . '/line_hook_one_line_file.inc';
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook($inc, 1, $p);

require $inc;
var_dump(olf_q());
echo count($log), ': ', implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(2)
3: 1,1,1
Done.
