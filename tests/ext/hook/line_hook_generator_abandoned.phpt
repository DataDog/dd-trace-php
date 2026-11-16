--TEST--
An abandoned generator still closes an open line hook range exactly once
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
    yield $b;               // 7
    $c = 3;                 // 8  end
    return 'ret';           // 9
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 8, $e);

$g = gen();
var_dump($g->current());
unset($g);                  // abandoned after the first yield
gc_collect_cycles();
echo implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(1)
B4,E8
Done.
