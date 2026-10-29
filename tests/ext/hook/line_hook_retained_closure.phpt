--TEST--
A line hook installed after an include reaches a closure returned by that include
--DESCRIPTION--
The include's file-scope op_array has been destroyed, but its returned closure is still alive.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$file = __DIR__ . '/line_hook_retained_closure.inc';
$closure = require $file;

$hits = 0;
$id = DDTrace\install_line_hook($file, 3, function () use (&$hits) { ++$hits; });

var_dump($closure());
var_dump($hits);
DDTrace\remove_hook($id);
?>
--EXPECT--
string(6) "called"
int(1)
