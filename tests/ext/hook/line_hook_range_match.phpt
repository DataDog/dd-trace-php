--TEST--
A line hook range spanning a match expression closes exactly once per arm taken
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip requires PHP 8.0'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function pick($v) {
    $pre = 'p';                 // 4  begin
    $r = match ($v) {
        1 => 'one',
        2 => 'two',
        default => 'other',
    };
    return $pre . $r;           // 10 end
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 10, $e);

foreach ([1, 2, 9] as $v) {
    $log = [];
    var_dump(pick($v));
    echo implode(',', $log), "\n";
}
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(4) "pone"
B4,E10
string(4) "ptwo"
B4,E10
string(6) "pother"
B4,E10
Done.
