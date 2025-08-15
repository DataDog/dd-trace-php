--TEST--
Test dynamic config update
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
DD_TRACE_AGENT_FLUSH_INTERVAL=333
--INI--
datadog.trace.agent_test_session_token=remote-config/dynamic_config_auto_update
--FILE--
<?php

require __DIR__ . "/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();

// submit span data
\DDTrace\start_span();

$path = put_dynamic_config_file([
    "log_injection_enabled" => true,
]);

sleep(20); // signal interrupts interrupt the sleep().

var_dump(ini_get("datadog.logs_injection"));

del_rc_file($path);

sleep(20);

var_dump(ini_get("datadog.logs_injection"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "1"
string(4) "true"
