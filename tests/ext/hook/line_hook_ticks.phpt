--TEST--
declare(ticks=1) inserts extra oplines that must not become arm targets
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
declare(ticks=1);

function tickish($t) {
    $a = $t;                        // 5
    $b = $a . '!';                  // 6
    return $b;                      // 7
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([5, 6, 7] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

var_dump(tickish('t'));
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(2) "t!"
5,6,7
Done.
