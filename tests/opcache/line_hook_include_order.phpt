--TEST--
Diagnostic: with opcache on, does install-before-include still arm?
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
opcache.protect_memory=0
opcache.optimization_level=0
--FILE--
<?php

$inc = __DIR__ . '/line_hook_double_include.inc';
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// installed before the file exists as an op_array
$a = DDTrace\install_line_hook($inc, 5, $p);
require $inc;
di_target(1);
echo 'before include: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($a);

// installed after the file is compiled and cached
$log = [];
$b = DDTrace\install_line_hook($inc, 5, $p);
di_target(2);
echo 'after include:  ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($b);

echo "Done.\n";
?>
--EXPECT--
before include: 5
after include:  5
Done.
