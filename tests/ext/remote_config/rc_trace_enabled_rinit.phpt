--TEST--
RC tracing_enabled=true during RINIT does not double-init request globals
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/rc_trace_enabled_rinit
--FILE--
<?php

if ($argc > 1) {
    var_dump(ini_get("datadog.trace.enabled"));
    exit();
}

require __DIR__ . "/remote_config.inc";
put_dynamic_config_file(["tracing_enabled" => true]);

if (!ini_get("datadog.trace.enabled")) {
    dd_trace_internal_fn("await_remote_config");
}

var_dump(ini_get("datadog.trace.enabled"));

$cmdAndArgs = explode("\0", file_get_contents("/proc/" . getmypid() . "/cmdline"));
if (!pcntl_fork()) {
    pcntl_exec(array_shift($cmdAndArgs), array_merge($cmdAndArgs, ["initialized"]));
} else {
    pcntl_wait($status);
}

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(1) "1"
string(1) "1"
