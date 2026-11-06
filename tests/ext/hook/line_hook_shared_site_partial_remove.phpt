--TEST--
Removing one of two hooks on the same site leaves the site armed for the other
--DESCRIPTION--
dd_line_def_drop_now() walks the def's own sites and disarms the ones its removal empties.
It deletes them from the armed table in place, so a site that another definition still holds an entry in must survive the walk untouched, and only the last holder's removal may disarm the opline.
Removing both without re-running the target in between -- which is what the nested-range tests do -- would not notice either mistake.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target() {
    return 1;                               // 4  both hooks arm this opline
}

$log = [];
$a = DDTrace\install_line_hook(__FILE__, 4, function () use (&$log) { $log[] = 'A'; });
$b = DDTrace\install_line_hook(__FILE__, 4, function () use (&$log) { $log[] = 'B'; });
var_dump($a < 0 && $b < 0);

target();
echo '[', implode(',', $log), "]\n";

$log = [];
DDTrace\remove_hook($a);
target();
echo '[', implode(',', $log), "]\n";

$log = [];
DDTrace\remove_hook($b);
target();
echo '[', implode(',', $log), "]\n";

echo "Done.\n";
?>
--EXPECT--
bool(true)
[A,B]
[B]
[]
Done.
