--TEST--
putenv triggers ddtrace config reload before next function is executed
--ENV--
DD_AGENT_HOST=initial
--FILE--
<?php
echo dd_trace_env_config('DD_AGENT_HOST') . PHP_EOL;
putenv('DD_AGENT_HOST=reconfigured');
echo dd_trace_env_config('DD_AGENT_HOST') . PHP_EOL;
?>
--EXPECT--
initial
reconfigured
