--TEST--
Hundreds of hooks on a single line all fire, and removal leaves the rest intact
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($n) {
    return $n + 1;              // 4
}

$hits = 0;
$p = function (DDTrace\LineHookData $h) use (&$hits) { ++$hits; };
$ids = [];
for ($i = 0; $i < 400; $i++) {
    $ids[] = DDTrace\install_line_hook(__FILE__, 4, $p);
}
var_dump(count(array_unique($ids)));

target(1);
var_dump($hits);

// Drop half of them from the middle out.
for ($i = 0; $i < 400; $i += 2) {
    DDTrace\remove_hook($ids[$i]);
}
$hits = 0;
target(2);
var_dump($hits);

foreach ($ids as $id) { DDTrace\remove_hook($id); }
$hits = 0;
target(3);
var_dump($hits);
echo "Done.\n";
?>
--EXPECT--
int(400)
int(400)
int(200)
int(0)
Done.
