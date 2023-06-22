--TEST--
Logs aliases are correctly handled
--ENV--
DD_LOGS_INJECTION=0
--FILE--
<?php

var_dump(\dd_trace_env_config("DD_LOGS_INJECTION"));
var_dump(\dd_trace_env_config("DD_TRACE_LOGS_ENABLED"));

ob_start();
phpinfo();
$phpinfo = ob_get_contents();
ob_end_clean();

$lines = explode("\n", $phpinfo);
foreach ($lines as $line) {
    if (strpos($line, "datadog.logs_injection") !== false || strpos($line, "datadog.trace.logs_enabled") !== false) {
        var_dump($line);
    }
}
--EXPECT--
bool(false)
bool(false)
string(36) "datadog.logs_injection => Off => Off"
string(40) "datadog.trace.logs_enabled => Off => Off"