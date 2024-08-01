--TEST--
The background sender informs about changes to the agent sample rate
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
DD_TRACE_AUTO_FLUSH_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_sampling
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

$get_sampling = function() use ($rr) {
    $root = json_decode($rr->waitForDataAndReplay()["body"], true);
    $spans = $root["chunks"][0]["spans"] ?? $root[0];
    return $spans[0]["metrics"]["_sampling_priority_v1"];
};

$rr->setResponse(["rate_by_service" => ["service:,env:" => 0]]);

\DDTrace\start_span();
\DDTrace\close_span();

echo "Initial sampling: {$get_sampling()}\n";

$rr->setResponse(["rate_by_service" => ["service:,env:" => 0, "service:foo,env:none" => 1]]);

\DDTrace\start_span();
\DDTrace\close_span();

echo "Generic sampling: {$get_sampling()}\n";

// reset it for other tests
$rr->setResponse(["rate_by_service" => []]);

$s = \DDTrace\start_span();
$s->service = "foo";
$s->env = "none";
\DDTrace\close_span();

echo "Specific sampling: {$get_sampling()}\n";

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
Initial sampling: 1
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
Generic sampling: 0
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80
Specific sampling: 1
[ddtrace] [info] No finished traces to be sent to the agent
