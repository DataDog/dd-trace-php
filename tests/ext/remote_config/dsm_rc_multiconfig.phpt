--TEST--
DSM2-611: data_streams_enabled follows the Remote Config priority merge
--DESCRIPTION--
The service+env config wins over the org-level one; removing it falls back to the org-level one.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_multiconfig
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();
\DDTrace\start_span();

$org = put_wildcard_dynamic_config_file(["data_streams_enabled" => true]);
$specific = put_dynamic_config_file(["data_streams_enabled" => false, "tracing_sampling_rate" => 0.41]);
await_remote_config(function () {
    return (float) ini_get("datadog.trace.sample_rate") === 0.41;
});
dsm_state("both configs");

dsm_remove_config($specific, 0.41);
dsm_state("specific removed");

del_rc_file($org);
await_remote_config(function () {
    return !dd_trace_env_config("DD_DATA_STREAMS_ENABLED");
});
dsm_state("all removed");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
both configs: setting=off checkpoint=none
specific removed: setting=on checkpoint=set
all removed: setting=off checkpoint=none
