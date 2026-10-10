--TEST--
[profiling] ddtrace rejects a separately loaded standalone profiler
--SKIPIF--
<?php
$ddtrace = getenv('DDTRACE_TEST_TRACER_EXTENSION');
$profiler = getenv('DDTRACE_TEST_PROFILER_EXTENSION');
if (!$ddtrace || !is_file($ddtrace)) {
    die('skip DDTRACE_TEST_TRACER_EXTENSION does not identify a ddtrace extension');
}
if (!$profiler || !is_file($profiler)) {
    die('skip DDTRACE_TEST_PROFILER_EXTENSION does not identify a datadog-profiling extension');
}
if (!getenv('TEST_PHP_EXECUTABLE')) {
    die('skip TEST_PHP_EXECUTABLE is unavailable');
}
?>
--FILE--
<?php
$php = getenv('TEST_PHP_EXECUTABLE');
$ddtrace = realpath(getenv('DDTRACE_TEST_TRACER_EXTENSION'));
$profiler = realpath(getenv('DDTRACE_TEST_PROFILER_EXTENSION'));

$command = escapeshellarg($php)
    . ' -n -d display_startup_errors=1'
    . ' -d extension=' . escapeshellarg($profiler)
    . ' -d extension=' . escapeshellarg($ddtrace)
    . ' -r ' . escapeshellarg('echo "unexpected execution\\n";')
    . ' 2>&1';
exec($command, $output, $status);
$text = implode("\n", $output);

var_dump($status !== 0);
var_dump(strpos($text, 'datadog-profiling cannot be loaded alongside ddtrace; use combined ddtrace profiling support instead') !== false);
?>
--EXPECT--
bool(true)
bool(true)
