--TEST--
Exception replay survives exceptions thrown by opcache's compile-time function evaluation
--DESCRIPTION--
opcache's SCCP pass evaluates @compile-time-eval functions such as strrpos()
with constant arguments while optimizing. It links a stack-allocated dummy
frame, whose func is the op_array being optimized but which has no CV slots,
as the caller of the evaluated function. Here SCCP still assumes $output === ''
and evaluates strrpos('', '/', -1), which throws a ValueError that opcache
discards. Capturing locals of the dummy frame read past it on the C stack
(crashes in the wild; detected as stack-buffer-overflow under ASAN).
--SKIPIF--
<?php if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required'); ?>
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.optimization_level=0x7FFEBFFF
opcache.file_update_protection=0
--FILE--
<?php

require __DIR__ . '/exception_replay_sccp_compile_time_eval.inc';

var_dump(remove_dot_segments_for_test('/a/b/../c'));
var_dump(opcache_is_script_cached(__DIR__ . '/exception_replay_sccp_compile_time_eval.inc'));

?>
--EXPECT--
string(4) "/a/c"
bool(true)
