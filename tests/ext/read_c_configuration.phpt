--TEST--
Ensure we can read C configuration data
--ENV--
DD_AGENT_HOST=some_known_host
DD_TRACE_MEMORY_LIMIT=9999
--FILE--

<?php
echo dd_trace_env_config("DD_AGENT_HOST");
echo PHP_EOL;
echo dd_trace_env_config("DD_TRACE_AGENT_PORT");
echo PHP_EOL;
echo dd_trace_env_config("DD_TRACE_AGENT_DEBUG_VERBOSE_CURL") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("DD_TRACE_DEBUG_CURL_OUTPUT") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("DD_TRACE_BETA_SEND_TRACES_VIA_THREAD") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("DD_TRACE_MEMORY_LIMIT");
echo PHP_EOL;
echo dd_trace_env_config("DD_NON_EXISTING_ENTRY") === NULL ? 'NULL' : 'NOT_NULL' ;
echo PHP_EOL;

// this format is for compatibility with existing configuration registry
echo dd_trace_env_config("agent.host");
echo PHP_EOL;
echo dd_trace_env_config("trace.agent.port");
echo PHP_EOL;
echo dd_trace_env_config("trace.agent.debug.verbose.curl") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("trace.debug.curl.output") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("trace.beta.send.traces.via.thread") ? 'TRUE' : 'FALSE';
echo PHP_EOL;
echo dd_trace_env_config("trace.memory.limit");
echo PHP_EOL;
echo dd_trace_env_config("non.existing.entry") === NULL ? 'NULL' : 'NOT_NULL' ;

?>
--EXPECT--
some_known_host
8126
FALSE
FALSE
FALSE
9999
NULL
some_known_host
8126
FALSE
FALSE
FALSE
9999
NULL
