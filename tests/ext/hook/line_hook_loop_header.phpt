--TEST--
Diagnostic: how often a hook on a loop header line fires
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function forHeader() {
    $s = 0;
    for ($i = 0; $i < 3; $i++) {    // 5
        $s += $i;
    }
    return $s;
}

function whileHeader() {
    $i = 0;
    while ($i < 3) {                // 13
        $i++;
    }
    return $i;
}

function doWhileHeader() {
    $i = 0;
    do {
        $i++;
    } while ($i < 3);               // 23
    return $i;
}

function foreachHeader() {
    $s = 0;
    foreach ([1, 2, 3] as $v) {     // 29
        $s += $v;
    }
    return $s;
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

foreach (['forHeader' => 5, 'whileHeader' => 13, 'doWhileHeader' => 23, 'foreachHeader' => 29] as $fn => $ln) {
    $id = DDTrace\install_line_hook(__FILE__, $ln, $p);
    $log = [];
    $fn();
    DDTrace\remove_hook($id);
    printf("%-14s line %d fired %d time(s)\n", $fn, $ln, count($log));
}

echo "Done.\n";
?>
--EXPECT--
forHeader      line 5 fired 1 time(s)
whileHeader    line 13 fired 1 time(s)
doWhileHeader  line 23 fired 3 time(s)
foreachHeader  line 29 fired 1 time(s)
Done.
