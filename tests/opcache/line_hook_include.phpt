--TEST--
A hook installed before an include still arms once opcache has persisted the op_array
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=1
opcache.optimization_level=-1
--FILE--
<?php

$inc = __DIR__ . '/line_hook_double_include.inc';
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook($inc, 5, $p);

require $inc;
var_dump(di_target(1));
var_dump(di_target(2));
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
var_dump(di_target(3));
echo count($log), "\n";
echo "Done.\n";
?>
--EXPECT--
int(11)
int(12)
5,5
int(13)
2
Done.
