--TEST--
Nested ranges that close at the same site close innermost first
--DESCRIPTION--
A begin/end pair is meant to be usable as a span, so ranges that nest must produce nested pairs: Ob,Ib,Ie,Oe, never Ob,Ib,Oe,Ie. line_hook_range_nested_and_overlapping.phpt gives its two ranges different end sites, so it cannot see the ordering.
Both shared-exit routes are covered here: a shared static end site, and a shared frame exit.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function shared_end() {
    $a = 1;                                 // 4  outer begin
    $b = 2;                                 // 5  inner begin
    $c = 3;                                 // 6  inner end and outer end
    return $a + $b + $c;                    // 7  first opline past either end line
}

function early_return($x) {
    $a = 1;                                 // 11 outer begin
    $b = 2;                                 // 12 inner begin
    if ($x) {                               // 13 still inside both
        return 'early';                     // 14 leaves both; only the frame guard sees it
    }
    $c = 3;                                 // 16
    return 'late';                          // 17
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag; };
};

// Outer installed first, so registration order and open order disagree with nesting order.
$o = DDTrace\install_line_hook(__FILE__, 4, $mk('Ob'), 6, $mk('Oe'));
$i = DDTrace\install_line_hook(__FILE__, 5, $mk('Ib'), 6, $mk('Ie'));
var_dump($o < 0 && $i < 0);
var_dump(shared_end());
echo 'shared end site: ', implode(',', $log), "\n";
DDTrace\remove_hook($o);
DDTrace\remove_hook($i);

$log = [];
$o2 = DDTrace\install_line_hook(__FILE__, 11, $mk('Ob'), 17, $mk('Oe'));
$i2 = DDTrace\install_line_hook(__FILE__, 12, $mk('Ib'), 16, $mk('Ie'));
var_dump($o2 < 0 && $i2 < 0);
var_dump(early_return(true));
echo 'shared frame exit: ', implode(',', $log), "\n";
DDTrace\remove_hook($o2);
DDTrace\remove_hook($i2);

echo "Done.\n";
?>
--EXPECT--
bool(true)
int(6)
shared end site: Ob,Ib,Ie,Oe
bool(true)
string(5) "early"
shared frame exit: Ob,Ib,Ie,Oe
Done.
