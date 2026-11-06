--TEST--
[profiling] CLI eval without a script path does not use a NULL basename offset
--SKIPIF--
<?php
if (!(extension_loaded('datadog-profiling') || ini_get('datadog.profiling.enabled') !== false))
    die('skip: requires profiling support');
if (!getenv('TEST_PHP_EXECUTABLE') || !getenv('TEST_PHP_EXTRA_ARGS'))
    die('skip: requires the PHPT runner executable and arguments');
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
DD_TRACE_ENABLED=false
DD_INSTRUMENTATION_TELEMETRY_ENABLED=false
DD_REMOTE_CONFIG_ENABLED=false
--FILE--
<?php
$command = escapeshellarg(getenv('TEST_PHP_EXECUTABLE'))
    . ' -n ' . getenv('TEST_PHP_EXTRA_ARGS')
    . ' -r ' . escapeshellarg('echo "eval\n";')
    . ' 2>&1';
exec($command, $output, $status);
echo implode("\n", $output), "\n";
var_dump($status);
?>
--EXPECT--
eval
int(0)
