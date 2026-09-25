--TEST--
A hook on an included file arms whether installed before, between or after the includes
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$inc = __DIR__ . '/line_hook_double_include.inc';
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// before any include
$a = DDTrace\install_line_hook($inc, 5, $p);
require $inc;
var_dump(di_target(1));
echo 'before: ', implode(',', $log), "\n";
DDTrace\remove_hook($a);

// between the two includes
$log = [];
$b = DDTrace\install_line_hook($inc, 5, $p);
require $inc;
var_dump(di_target(2));
echo 'between: ', implode(',', $log), "\n";
DDTrace\remove_hook($b);

// after both includes
$log = [];
$c = DDTrace\install_line_hook($inc, 5, $p);
var_dump(di_target(3));
echo 'after: ', implode(',', $log), "\n";
DDTrace\remove_hook($c);

echo "Done.\n";
?>
--EXPECT--
int(11)
before: 5
int(12)
between: 5
int(13)
after: 5
Done.
