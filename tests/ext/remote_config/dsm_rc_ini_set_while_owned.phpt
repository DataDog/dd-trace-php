--TEST--
DSM2-611: ini_set while Remote Config owns a setting is rejected, but its value is what removal restores
--DESCRIPTION--
Known quirk shared by every dynamic-config key (ddog_remote_config_alter_dynamic_config records the
rejected value as the one to restore), pinned here for data_streams_enabled and for a pre-existing
key, datadog.trace.sample_rate. After the RC key is removed the setting is the rejected ini_set
value, not the env value. If the quirk is fixed, the "after removal" lines become the env values
("0" and "0.9"), and this test must be updated.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DATA_STREAMS_ENABLED=0
DD_TRACE_SAMPLE_RATE=0.9
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_ini_set_while_owned
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();
\DDTrace\start_span();

$path = put_dynamic_config_file(["data_streams_enabled" => true, "tracing_sampling_rate" => 0.45]);
await_remote_config(function () {
    return (float) ini_get("datadog.trace.sample_rate") === 0.45;
});

print "ini_set while owned:\n";
var_dump(ini_set("datadog.data_streams_enabled", "1"));
var_dump(ini_set("datadog.trace.sample_rate", "0.7"));
var_dump(ini_get("datadog.data_streams_enabled"), ini_get("datadog.trace.sample_rate"));

del_rc_file($path);
await_remote_config(function () {
    return (float) ini_get("datadog.trace.sample_rate") !== 0.45;
});

print "after removal:\n";
var_dump(ini_get("datadog.data_streams_enabled"), ini_get("datadog.trace.sample_rate"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
ini_set while owned:
bool(false)
bool(false)
string(1) "1"
string(4) "0.45"
after removal:
string(1) "1"
string(3) "0.7"
