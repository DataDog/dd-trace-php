--TEST--
Client-side stats are enabled by default and can be disabled
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

echo "DD_TRACE_STATS_COMPUTATION_ENABLED=";
echo dd_trace_env_config("DD_TRACE_STATS_COMPUTATION_ENABLED") ? "true" : "false";
echo "\n";

ini_set('datadog.trace.stats_computation_enabled', '0');
echo "DD_TRACE_STATS_COMPUTATION_ENABLED=";
echo dd_trace_env_config("DD_TRACE_STATS_COMPUTATION_ENABLED") ? "true" : "false";
echo "\n";

?>
--EXPECT--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
DD_TRACE_STATS_COMPUTATION_ENABLED=false
