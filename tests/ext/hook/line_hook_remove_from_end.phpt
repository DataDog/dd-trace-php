--TEST--
Removing a range from inside its own end callback, and re-installing it, stays consistent
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
$again = null;
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = null;
$e = function (DDTrace\LineHookData $h) use (&$log, &$again, &$b, &$e) {
    $log[] = 'E' . $h->line;
    DDTrace\remove_hook($h->id);
    if ($again === null) {
        $again = DDTrace\install_line_hook(__FILE__, 4, $b, 6, $e);
    }
};

$id = DDTrace\install_line_hook(__FILE__, 4, $b, 6, $e);
var_dump(work(1));
var_dump(work(2));
var_dump(work(3));
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
if ($again !== null) { DDTrace\remove_hook($again); }
echo "Done.\n";
?>
--EXPECT--
int(2)
int(3)
int(4)
B4,E6,B4,E6
bool(true)
Done.
