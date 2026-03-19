--TEST--
Client-side stats: DD_TRACE_STATS_COMPUTATION_ENABLED config can be read
--ENV--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
--FILE--
<?php

echo "DD_TRACE_STATS_COMPUTATION_ENABLED=";
echo dd_trace_env_config("DD_TRACE_STATS_COMPUTATION_ENABLED") ? "true" : "false";
echo "\n";

?>
--EXPECT--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
