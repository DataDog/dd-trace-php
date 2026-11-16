--TEST--
FFE signal flush waits for pending HTTP sends before default-handler termination
--DESCRIPTION--
Default signal handling deliberately calls _exit without normal cleanup.
Keep Valgrind's runtime checks, but skip the final leak scan for this test:
libdatadog's unbacked shared-memory reservations make that scan very slow.
Normal-shutdown tests keep their existing Valgrind settings.
--SKIPIF--
<?php
if (PHP_OS !== 'Linux') die('skip: prepared signal flush is Linux-only');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('posix_kill') || !function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: signals and subprocess sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_AGENT_TIMEOUT=5000
DD_SERVICE=php-ffe-signal-flush
DD_TRACE_FORCE_FLUSH_ON_SIGTERM=1
DD_TRACE_FORCE_FLUSH_ON_SIGINT=1
VALGRIND_OPTS=--leak-check=no
--FILE--
<?php
$modes = array('sigterm', 'sigint');
require __DIR__ . '/stubs/flag_evaluation_flush.inc';
?>
--EXPECT--
false/sigterm: bool(true)
false/sigint: bool(true)
true/sigterm: bool(true)
true/sigint: bool(true)
