--TEST--
A line hook left installed at request end must not write back into an op_array the engine already freed
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable_cli=0
--FILE--
<?php

// Without opcache the main script's op_array is destroyed by zend_execute_script() -- and an include's the moment it returns -- both well before ddtrace's RSHUTDOWN.
// Neither hook is removed here, so RSHUTDOWN still holds their addresses; restoring opline->handler at that point would write into freed memory.
require __DIR__ . '/line_hook_no_remove.inc';

$hits = 0;
DDTrace\install_line_hook(__FILE__, 12, function () use (&$hits) { $hits++; });
DDTrace\install_line_hook(__DIR__ . '/line_hook_no_remove.inc', 4, function () use (&$hits) { $hits++; });

$x = 1;
$y = 2;
included_target();

var_dump($hits);
echo "Done.\n";
?>
--EXPECT--
int(2)
Done.
