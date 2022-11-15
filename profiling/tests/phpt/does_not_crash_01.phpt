--TEST--
[profiling] does not crash if profiling (cpu and allocation) is enabled
--DESCRIPTION--
Might crash (did so during development) when during an interrupt an allocation
profile is beeing created. Two calls to `borrow_mut()` on `REQUEST_LOCALS`
lead to a Rust panic
--CGI--
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=error
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

assert(extension_loaded('datadog-profiling'));

ob_start();
phpinfo();
ob_end_clean();

echo "Done.";

?>
--EXPECT--
Done.
