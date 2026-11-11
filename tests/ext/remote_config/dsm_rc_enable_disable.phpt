--TEST--
DSM2-611: Remote Config enables and disables DSM at runtime, including re-enabling
--DESCRIPTION--
DSM is off at startup, and nothing in the process asks for the sidecar to start for it.
The sidecar is up for telemetry, and checkpoints work once Remote Config turns the setting on.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_enable_disable
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();
\DDTrace\start_span();

dsm_state("startup");

$path = dsm_put_config(["data_streams_enabled" => true], 0.11);
dsm_state("enabled");
// Only data_streams_enabled was asked for.
var_dump(ini_get("datadog.trace.enabled"));

dsm_remove_config($path, 0.11);
dsm_state("key removed");

$path = dsm_put_config(["data_streams_enabled" => true], 0.12);
dsm_state("enabled again");

dsm_remove_config($path, 0.12);
dsm_state("key removed again");

$path = dsm_put_config(["data_streams_enabled" => false], 0.13);
dsm_state("explicit false");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
startup: setting=off checkpoint=none
enabled: setting=on checkpoint=set
string(1) "1"
key removed: setting=off checkpoint=none
enabled again: setting=on checkpoint=set
key removed again: setting=off checkpoint=none
explicit false: setting=off checkpoint=none
