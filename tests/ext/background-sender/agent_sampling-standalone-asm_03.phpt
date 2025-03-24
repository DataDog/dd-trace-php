--TEST--
Sample rate is not changed to 0 after first call during a minute when there is appsec upstream
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_APM_TRACING_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
HTTP_X_DATADOG_TAGS=_dd.p.ts=02
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_samplinga
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

$get_sampling = function() use ($rr) {
    $root = json_decode($rr->waitForDataAndReplay()["body"], true);
    $spans = $root["chunks"][0]["spans"] ?? $root[0];
    return $spans[0]["metrics"]["_sampling_priority_v1"];
};

\DDTrace\start_span();
\DDTrace\close_span();
echo "First call it is used as heartbeat: {$get_sampling()}\n";

dd_trace_internal_fn("synchronous_flush");

\DDTrace\start_span();
\DDTrace\close_span();
echo "This call has the same sample rate: {$get_sampling()}\n";

// reset it for other tests
dd_trace_internal_fn("synchronous_flush");

\DDTrace\start_span();
\DDTrace\close_span();
echo "This call also has the same sample rate: {$get_sampling()}\n";

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
First call it is used as heartbeat: 3
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
This call has the same sample rate: 3
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
This call also has the same sample rate: 3
[ddtrace] [info] No finished traces to be sent to the agent
