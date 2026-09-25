--TEST--
A throwing begin callback is sandboxed and the range still closes exactly once
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function work($n) {
    $a = $n;            // 4  begin
    $b = $a + 1;        // 5
    return $b;          // 6  end
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = 'B' . $h->line;
    throw new RuntimeException('from begin');
};
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 6, $e);

var_dump(work(1));
var_dump(work(2));
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(2)
int(3)
B4,E6,B4,E6
bool(true)
Done.
