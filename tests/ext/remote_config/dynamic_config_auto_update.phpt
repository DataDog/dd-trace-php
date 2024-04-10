--TEST--
Test dynamic config update
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

require __DIR__ . "/remote_config.inc";

reset_request_replayer();

// submit span data
\DDTrace\start_span();

$path = put_dynamic_config_file([
    "log_injection_enabled" => true,
]);

usleep(100000);

var_dump(ini_get("datadog.logs_injection"));

del_rc_file($path);

usleep(100000);

var_dump(ini_get("datadog.logs_injection"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "1"
string(5) "false"
