--TEST--
Nested ranges that open at the same site open outermost first
--DESCRIPTION--
The companion to line_hook_nested_shared_end.phpt.
Closing in reverse-open order only yields nested pairs if the opening order was nesting order to begin with, and site order is installation order -- which says nothing about nesting.
Installing the inner range first is what exposes it.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target() {
    $a = 1;                                 // 4  both ranges begin here
    $b = 2;                                 // 5  inner ends
    $c = 3;                                 // 6  outer ends
    return $a + $b + $c;
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function () use ($tag, &$log) { $log[] = $tag; };
};

// Inner installed first, so registration order and nesting order disagree.
$inner = DDTrace\install_line_hook(__FILE__, 4, $mk('Ib'), 5, $mk('Ie'));
$outer = DDTrace\install_line_hook(__FILE__, 4, $mk('Ob'), 6, $mk('Oe'));
var_dump($inner < 0 && $outer < 0);
var_dump(target());
echo implode(',', $log), "\n";

DDTrace\remove_hook($inner);
DDTrace\remove_hook($outer);
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(6)
Ob,Ib,Ie,Oe
Done.
