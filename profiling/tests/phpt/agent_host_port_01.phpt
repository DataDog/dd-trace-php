--TEST--
[profiling] DD_AGENT_HOST and DD_TRACE_AGENT_PORT configure the profiler endpoint
--SKIPIF--
<?php
if (!(extension_loaded('datadog-profiling') || ini_get('datadog.profiling.enabled') !== false))
    die("skip: test requires Datadog Continuous Profiler\n");
?>
--ENV--
DD_AGENT_HOST=example.test
DD_TRACE_AGENT_PORT=4321
DD_PROFILING_ENABLED=1
--FILE--
<?php
ob_start();
$extension = new ReflectionExtension(extension_loaded('datadog-profiling') ? 'datadog-profiling' : 'ddtrace');
$extension->info();
$output = ob_get_clean();

preg_match('/Profiling Agent Endpoint\s*=>\s*([^\r\n]+)/', $output, $matches);
echo trim($matches[1] ?? 'missing'), "\n";
?>
--EXPECT--
http://example.test:4321/
