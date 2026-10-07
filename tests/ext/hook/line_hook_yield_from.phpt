--TEST--
A line hook range across yield from closes once per generator frame
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function inner() {
    $a = 'i1';              // 4  inner begin
    yield $a;
    $b = 'i2';
    yield $b;               // 7  inner end (closes at the implicit return, line 8)
}

function outer() {
    $x = 'o';               // 11 outer begin
    yield from inner();
    yield $x;               // 13 outer end
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};
$i = DDTrace\install_line_hook(__FILE__, 4, $mk('Ib'), 7, $mk('Ie'));
$o = DDTrace\install_line_hook(__FILE__, 11, $mk('Ob'), 13, $mk('Oe'));

foreach (outer() as $v) {
    var_dump($v);
}
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'Ib') === substr_count($s, 'Ie'));
var_dump(substr_count($s, 'Ob') === substr_count($s, 'Oe'));
DDTrace\remove_hook($i);
DDTrace\remove_hook($o);
echo "Done.\n";
?>
--EXPECT--
string(2) "i1"
string(2) "i2"
string(1) "o"
Ob11,Ib4,Ie8,Oe14
bool(true)
bool(true)
Done.
