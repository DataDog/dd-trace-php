--TEST--
Two function bodies on one source line are both armed and each fires once
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line . ':' . $h->var('t'); };
$id = DDTrace\install_line_hook(__FILE__, 6, $p);

function aa($t){ $x = $t; return $x; } function bb($t){ $y = $t; return $y; }

var_dump(aa('A'));
var_dump(bb('B'));
echo implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(1) "A"
string(1) "B"
6:A,6:B
Done.
