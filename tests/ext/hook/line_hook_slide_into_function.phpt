--TEST--
A request before a function's declaration must not slide past the function's body
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$probe = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// Requested 8 (blank). The smallest executable line >= 8 in the whole file is 10, inside f().
$a = DDTrace\install_line_hook(__FILE__, 8, $probe);

function f() {
    $x = 1;
}

f();
echo 'from 8: ', implode(',', $log), "\n";
DDTrace\remove_hook($a);

// Requested 9 (the declaration itself) resolves to the same 10.
$log = [];
$b = DDTrace\install_line_hook(__FILE__, 9, $probe);
f();
echo 'from 9: ', implode(',', $log), "\n";
DDTrace\remove_hook($b);

echo "Done.\n";
?>
--EXPECT--
from 8: 10
from 9: 10
Done.
