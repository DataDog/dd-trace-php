--TEST--
Test dynamic config multiconfig priority merging
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
--INI--
datadog.trace.agent_test_session_token=remote-config/dynamic_config_multiconfig
--FILE--
<?php

require __DIR__ . "/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();

\DDTrace\start_span();

// Add both configs before sleeping so a single polling cycle sees both.
// Org-level: sets sample_rate=0.3 and log_injection=true.
// Specific service+env: overrides sample_rate=0.7, does not set log_injection.
$org_path = put_wildcard_dynamic_config_file([
    "tracing_sample_rate" => 0.3,
    "log_injection_enabled" => true,
]);
$specific_path = put_dynamic_config_file([
    "tracing_sample_rate" => 0.7,
]);

sleep(20); // signal interrupts interrupt the sleep().

// Specific config wins for sample_rate; org-level provides log_injection.
print "After both configs:\n";
var_dump(ini_get("datadog.trace.sample_rate"));
var_dump(ini_get("datadog.logs_injection"));

del_rc_file($specific_path);

sleep(20); // signal interrupts interrupt the sleep().

// Only org-level remains: sample_rate falls back to 0.3.
print "After removing specific config:\n";
var_dump(ini_get("datadog.trace.sample_rate"));
var_dump(ini_get("datadog.logs_injection"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
After both configs:
string(3) "0.7"
string(1) "1"
After removing specific config:
string(3) "0.3"
string(1) "1"
