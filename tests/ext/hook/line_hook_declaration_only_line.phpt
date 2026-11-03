--TEST--
A line holding nothing but a function declaration fires only inside the function
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line . ':' . var_export($h->var('t'), true); };
$id = DDTrace\install_line_hook(__FILE__, 6, $p);

function decl_only($t) { return $t; }

var_dump(decl_only('A'));
echo count($log), ': ', implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(1) "A"
1: 6:'A'
Done.
