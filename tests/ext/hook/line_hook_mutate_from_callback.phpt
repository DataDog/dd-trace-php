--TEST--
Installing and removing line hooks from inside a line hook callback is safe
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function step($n) {
    $a = $n;            // 4
    $b = $a + 1;        // 5
    return $b;          // 6
}

$log = [];
$second = null;
$p = function (DDTrace\LineHookData $h) use (&$log, &$second) {
    $log[] = 'first' . $h->line;
    if ($second === null) {
        // arm a different line from inside a callback
        $second = DDTrace\install_line_hook(__FILE__, 5, function (DDTrace\LineHookData $g) use (&$log) {
            $log[] = 'second' . $g->line;
        });
    }
    // and drop ourselves
    DDTrace\remove_hook($h->id);
};

$first = DDTrace\install_line_hook(__FILE__, 4, $p);
var_dump(step(1));
var_dump(step(2));
echo implode(',', $log), "\n";
DDTrace\remove_hook($second);
$log = [];
var_dump(step(3));
echo count($log), "\n";
echo "Done.\n";
?>
--EXPECT--
int(2)
int(3)
first4,second5,second5
int(4)
0
Done.
