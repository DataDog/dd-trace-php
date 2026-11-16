--TEST--
CLI eval without a script path does not use a NULL basename offset
--DESCRIPTION--
CLI auto-disable checks the basename of argv[0] for Composer. For php -r,
argv[0] has no path separator; neither strrchr result may be incremented
unless it is non-NULL. This also exercises the path under UBSan builds.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('exec')) die('skip: exec required');
if (!getenv('TEST_PHP_EXECUTABLE') || !getenv('TEST_PHP_EXTRA_ARGS'))
    die('skip: requires the PHPT runner executable and arguments');
?>
--ENV--
DD_TRACE_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_LOG_LEVEL=off
--FILE--
<?php
// Keep CLI_ENABLED at its default in the child, otherwise auto-disable is bypassed.
putenv('DD_TRACE_CLI_ENABLED');
$command = escapeshellarg(getenv('TEST_PHP_EXECUTABLE'))
    . ' -n ' . getenv('TEST_PHP_EXTRA_ARGS')
    . ' -r ' . escapeshellarg("echo 'eval', PHP_EOL; var_dump((bool) ini_get('datadog.trace.cli_enabled'));")
    . ' 2>&1';
exec($command, $output, $status);
echo implode("\n", $output), "\n";
var_dump($status);
?>
--EXPECT--
eval
bool(true)
int(0)
