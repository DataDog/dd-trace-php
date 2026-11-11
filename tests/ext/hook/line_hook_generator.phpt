--TEST--
Line hook ranges in a generator pair every begin with exactly one end across suspensions
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function gen() {
    $a = 1;                 // 4  begin
    yield $a;               // 5
    $b = 2;                 // 6
    yield $b;               // 7  end
    return 'ret';           // 8
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 7, $e);

$g = gen();
foreach ($g as $v) {
    var_dump($v);
}
var_dump($g->getReturn());
echo implode(',', $log), "\n";
$begins = substr_count(implode(',', $log), 'B');
$ends = substr_count(implode(',', $log), 'E');
var_dump($begins === $ends, $begins);
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(1)
int(2)
string(3) "ret"
B4,E8
bool(true)
int(1)
Done.
