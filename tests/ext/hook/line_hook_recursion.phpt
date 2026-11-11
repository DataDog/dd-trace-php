--TEST--
Recursive frames each open and close their own line hook range
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function fact($n) {
    $r = 1;                                 // 4  begin
    if ($n > 1) {
        $r = $n * fact($n - 1);
    }
    return $r;                              // 8  end
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->var('n'); };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->var('n'); };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 8, $e);

var_dump(fact(4));
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(24)
B4,B3,B2,B1,E1,E2,E3,E4
bool(true)
Done.
