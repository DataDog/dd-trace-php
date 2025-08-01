--TEST--
Test dynamic config update
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
--INI--
datadog.trace.agent_test_session_token=remote-config/dynamic_config_update
--FILE--
<?php

require __DIR__ . "/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();

put_dynamic_config_file([
    "tracing_sample_rate" => 0.5,
    "tracing_header_tags" => [["header" => "foo", "tag_name" => "bar"], ["header" => "other", "tag_name" => "baz"]],
    "log_injection_enabled" => true,
    "tracing_tags" => ["foo:bar", "baz:qux"],
    "tracing_enabled" => true,
    "tracing_sampling_rules" => [
        [
            "service" => "foo",
            "resource" => "bar",
            "provenance" => "customer",
            "sample_rate" => 0,
        ],
        [
            "service" => "f?o",
            "resource" => "b*r",
            "name" => "*",
            "provenance" => "dynamic",
            "sample_rate" => 0,
            "tags" => [
                ["key" => "vuz", "value_glob" => "v?"],
            ],
        ],
    ],
]);

// submit span data
\DDTrace\start_span();

if (ini_get("datadog.trace.sample_rate") != 0.5) {
    sleep(20); // signal interrupts interrupt the sleep().
}

var_dump(ini_get("datadog.trace.sample_rate"));
$tags = explode(",", ini_get("datadog.trace.header_tags"));
sort($tags); // rust has no stable sorting in hashmaps, but that's fine
var_dump(implode(",", $tags));
var_dump(ini_get("datadog.logs_injection"));
var_dump(ini_get("datadog.tags"));
var_dump(ini_get("datadog.trace.enabled"));
var_dump(ini_get("datadog.trace.sampling_rules"));

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
string(3) "0.5"
string(9) "foo,other"
string(1) "1"
string(15) "foo:bar,baz:qux"
string(1) "1"
string(187) "[{"service":"foo","resource":"bar","_provenance":"customer","sample_rate":0.0},{"name":"*","service":"f?o","resource":"b*r","tags":{"vuz":"v?"},"_provenance":"dynamic","sample_rate":0.0}]"
