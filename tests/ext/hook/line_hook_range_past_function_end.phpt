--TEST--
An endLine past the enclosing function's last line still closes the range once, at frame exit
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function small() {
    $a = 1;                     // 4  begin
    return $a;                  // 5
}

function later() {
    return 'later';             // 9
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };

// endLine 9 lies inside another function entirely.
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 9, $e);
var_dump(small());
var_dump(later());
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(1)
string(5) "later"
B4,E9
bool(true)
Done.
