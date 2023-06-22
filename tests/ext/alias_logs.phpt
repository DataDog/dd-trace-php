--TEST--
Logs aliases are correctly handled
--ENV--
DD_LOGS_INJECTION=0
--FILE--
<?php

var_dump(\dd_trace_env_config("DD_LOGS_INJECTION"));
var_dump(\dd_trace_env_config("DD_TRACE_LOGS_ENABLED"));
--EXPECT--
bool(false)
bool(false)