--TEST--
Two nested ranges in one function each get exactly one end; partially overlapping ranges are rejected
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function body() {
    $a = 1;                     // 4  outer begin
    $b = 2;                     // 5  inner begin
    $c = 3;                     // 6  inner end
    $d = 4;                     // 7  outer end
    return $a + $b + $c + $d;   // 8  first opline past either end line
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

$outer = DDTrace\install_line_hook(__FILE__, 4, $mk('Ob'), 7, $mk('Oe'));
$inner = DDTrace\install_line_hook(__FILE__, 5, $mk('Ib'), 6, $mk('Ie'));
var_dump($outer < 0 && $inner < 0);

var_dump(body());
echo implode(',', $log), "\n";
DDTrace\remove_hook($outer);
DDTrace\remove_hook($inner);

// Partially overlapping (neither nested nor disjoint) ranges are refused.
$x = DDTrace\install_line_hook(__FILE__, 4, $mk('Xb'), 6, $mk('Xe'));
try {
    DDTrace\install_line_hook(__FILE__, 5, $mk('Yb'), 7, $mk('Ye'));
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$log = [];
var_dump(body());
echo implode(',', $log), "\n";
DDTrace\remove_hook($x);
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(10)
Ob4,Ib5,Ie7,Oe8
Line hook range partially overlaps an existing range in the same file
int(10)
Xb4,Xe7
Done.
