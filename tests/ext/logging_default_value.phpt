--TEST--
Assess that the log integration is disabled by default
--FILE--
<?php

var_dump(\dd_trace_env_config("DD_LOGS_INJECTION"));
var_dump(\dd_trace_env_config("DD_TRACE_LOGS_ENABLED"));

ini_set("datadog.logs_injection", "true");

var_dump(\dd_trace_env_config("DD_LOGS_INJECTION"));
var_dump(\dd_trace_env_config("DD_TRACE_LOGS_ENABLED"));

?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
