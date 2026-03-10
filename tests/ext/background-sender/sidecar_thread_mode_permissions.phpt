--TEST--
Thread mode sidecar uses abstract Unix socket
--SKIPIF--
<?php if (PHP_OS != "Linux") die('skip: Linux abstract socket test'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not available on Windows'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--FILE--
<?php
// Trigger sidecar initialization
DDTrace\start_span();
DDTrace\close_span();

$pid = getmypid();
$pattern = sys_get_temp_dir() . "/libdatadog/libdd.*@{$pid}.sock";

// Wait briefly then verify no filesystem socket was created
usleep(200000); // 200ms

$sockets = glob($pattern);
echo count($sockets) === 0 ? "Sidecar uses abstract socket\n" : "Unexpected filesystem socket found\n";
?>
--EXPECT--
Sidecar uses abstract socket
