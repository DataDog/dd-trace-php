--TEST--
Thread mode sidecar: socket name encodes master uid for setuid compatibility
--SKIPIF--
<?php if (PHP_OS != "Linux") die('skip: Linux-specific socket path test'); ?>
<?php if (!function_exists('posix_geteuid')) die('skip: requires posix extension'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not available on Windows'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--FILE--
<?php
$pid = getmypid();
$uid = posix_geteuid();

$pattern = sys_get_temp_dir() . "/libdatadog/libdd.*@{$uid}-{$pid}.sock";
$sockets = glob($pattern);

if (count($sockets) > 0) {
    echo "Socket found with correct uid-pid encoding\n";
} else {
    echo "No thread-mode socket found (sidecar may not have started)\n";
}

?>
--EXPECT--
Socket found with correct uid-pid encoding
