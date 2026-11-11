--TEST--
DSM2-611: Remote Config cannot enable DSM when nothing starts the sidecar
--DESCRIPTION--
Known limit. Remote Config runs on the sidecar, and with telemetry and the sidecar trace sender off
(and no other reason for it) the sidecar is not started, so no Remote Config setting applies.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the sidecar trace sender is always on on Windows'); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_requires_sidecar
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
\DDTrace\start_span();

put_dynamic_config_file(["data_streams_enabled" => true, "tracing_sampling_rate" => 0.61]);
await_remote_config(function () {
    return (float) ini_get("datadog.trace.sample_rate") === 0.61;
}, 3);

var_dump((float) ini_get("datadog.trace.sample_rate") === 0.61);
dsm_state("remote config sent");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
bool(false)
remote config sent: setting=off checkpoint=none
