--TEST--
DDTrace\install_line_hook() fires at the requested line and stops on remove_hook()
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($n) {
    $a = $n * 2;
    $b = $a + 1;
    return $b;
}

$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 5, function () use (&$hits) { ++$hits; });
var_dump($id < 0);

var_dump(target(5));
var_dump(target(7));
var_dump($hits);

DDTrace\remove_hook($id);
var_dump(target(9));
var_dump($hits);

// A range whose end precedes its start is rejected before anything is armed.
try {
    DDTrace\install_line_hook(__FILE__, 10, null, 4);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

echo "Done.\n";
?>
--EXPECT--
bool(true)
int(11)
int(15)
int(2)
int(19)
int(2)
Line hook end line 4 is before start line 10
Done.
