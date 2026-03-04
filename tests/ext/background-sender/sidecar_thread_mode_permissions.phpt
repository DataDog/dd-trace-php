--TEST--
Thread mode sidecar: socket name encodes master uid for setuid compatibility
--DESCRIPTION--
PHP-FPM workers commonly run as www-data while the master process started as
root. The thread-mode socket name encodes the master's effective uid so that
a child process which has since dropped privileges via setuid() still computes
the same socket path and can connect to the master listener.
/tmp/libdatadog/ is made world-writable (best-effort) by ensure_dir_exists so
any user can create sockets there.
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

// By the time PHP code executes, the sidecar master listener thread has already
// been started during RINIT. The socket and lock files must be visible now.

$pid = getmypid();
$uid = posix_geteuid();

// Thread-mode sockets live in /tmp/libdatadog/ with the master uid encoded:
//   /tmp/libdatadog/libdd.<version>@<uid>-<pid>.sock
// The uid in the name ensures a post-setuid child still finds the same path.
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
