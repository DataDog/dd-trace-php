--TEST--
Lines past the end of the file, and invalid line arguments, are handled without crashing
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// Well past EOF: nothing to arm, but the call must still succeed and stay inert.
$a = DDTrace\install_line_hook(__FILE__, 100000, $p);
var_dump($a < 0);

function noop() { return 1; }
noop();
var_dump(count($log));
DDTrace\remove_hook($a);

foreach ([0, -1] as $bad) {
    try {
        DDTrace\install_line_hook(__FILE__, $bad, $p);
        echo "no error for $bad\n";
    } catch (Error $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

// A file that does not exist at all.
$b = DDTrace\install_line_hook('/definitely/not/here.php', 5, $p);
var_dump($b < 0);
DDTrace\remove_hook($b);

// Removing an id twice, and an id that was never handed out.
DDTrace\remove_hook(999999);
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(0)
Error: Line hook end line 0 is before start line 0
Error: Line hook end line -1 is before start line -1
bool(true)
Done.
