--TEST--
[profiling] does not crash if profiling (cpu and allocation) is enabled
--DESCRIPTION--
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  echo "skip: test requires Datadog Continuous Profiler\n";
?>
--CGI--
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=info
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=yes
DD_SERVICE=datadog-profiling-phpt
DD_ENV=dev
DD_VERSION=13
DD_AGENT_HOST=localh0st
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_URL=http://datadog:8126
--INI--
assert.exception=1
--FILE--
<?php

ob_start();
phpinfo();
ob_end_clean();

echo "Done.";

?>
--EXPECT--
Done.
