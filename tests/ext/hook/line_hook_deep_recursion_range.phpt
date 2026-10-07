--TEST--
A deep recursion holds one open range per frame and closes every one of them
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function down($n) {
    $here = $n;                     // 4  begin
    if ($n > 0) {
        down($n - 1);
    }
    return $here;                   // 8  end
}

$begins = 0;
$ends = 0;
$b = function (DDTrace\LineHookData $h) use (&$begins) { ++$begins; };
$e = function (DDTrace\LineHookData $h) use (&$ends) { ++$ends; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 8, $e);

down(500);
var_dump($begins, $ends, $begins === $ends);
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(501)
int(501)
bool(true)
Done.
