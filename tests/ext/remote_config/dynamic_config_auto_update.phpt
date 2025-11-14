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
DD_DYNAMIC_INSTRUMENTATION_ENABLED=0
DD_EXCEPTION_REPLAY_ENABLED=1
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=1
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
    "dynamic_instrumentation_enabled" => true, // note: user-provided false overwrites
    "exception_replay_enabled" => false,
    "code_origin_enabled" => false,
]);

sleep(20); // signal interrupts interrupt the sleep().

var_dump(ini_get("datadog.logs_injection"));
var_dump(ini_get("datadog.dynamic_instrumentation.enabled"));
var_dump(ini_get("datadog.exception_replay_enabled"));
var_dump(ini_get("datadog.code_origin_for_spans_enabled"));

del_rc_file($path);

print "After RC:\n";
sleep(20);

var_dump(ini_get("datadog.logs_injection"));
var_dump(ini_get("datadog.dynamic_instrumentation.enabled"));
var_dump(ini_get("datadog.exception_replay_enabled"));
var_dump(ini_get("datadog.code_origin_for_spans_enabled"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "1"
string(1) "0"
string(1) "0"
string(1) "0"
After RC:
string(4) "true"
string(1) "0"
string(1) "1"
string(1) "1"
