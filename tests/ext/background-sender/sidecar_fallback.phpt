--TEST--
Send telemetry about the sidecar being disabled
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (PHP_VERSION_ID < 80400) die('skip: Sidecar fallback exists only on PHP 8.4'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip: valgrind reports sendmsg(msg.msg_control) points to uninitialised byte(s), but it is unproblematic and outside our control in rust code'); ?>
<?php include __DIR__ . '/../includes/request_replayer.inc'; $rr = new RequestReplayer; usleep(5000); $rr->replayRequest(); /* avoid cross-pollination */ ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_SERVICE=service
--INI--
datadog.trace.agent_test_session_token=background-sender/sidecar_fallback
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer;

var_dump(ini_get("datadog.trace.sidecar_trace_sender"));
print $rr->waitForDataAndReplay(false)["body"];

?>
--EXPECTF--
string(1) "0"
{
    "api_version": "v2",
    "request_type": "generate-metrics",
    "seq_id": 1,
    "runtime_id": "%s-%s-%s-%s-%s",
    "tracer_time": %d,
    "payload": {
        "namespace": "tracers",
        "series": [
            {
                "metric": "exporter_fallback",
                "tags": [
                    "reason:instrumentation_telemetry_disabled"
                ],
                "points": [
                    [
                        %d,
                        1
                    ]
                ],
                "type": "count",
                "common": true
            }
        ]
    },
    "application": {
        "service_name": "service",
        "tracer_version": "%s",
        "language_name": "php",
        "language_version": "%s"
    },
    "host": {
        "hostname": "%s"
    }
}
