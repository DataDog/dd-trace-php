--TEST--
An immediately invoked closure shares its line with the enclosing scope, so both sites arm
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 7, $p);

$r = (function () { return 'iife'; })();

var_dump($r);
echo count($log), ': ', implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(4) "iife"
2: 7,7
Done.
