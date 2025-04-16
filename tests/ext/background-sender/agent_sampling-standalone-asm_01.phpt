--TEST--
Sample rate is changed to 0 after first call during a minute when STANDALONE ASM is enabled and no asm events
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_APM_TRACING_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_samplingc
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

$picked = 0;
$notPicked = 0;
for ($i = 0; $i < 5; $i++)
{
    //Do call and get sampling
    \DDTrace\start_span();
    \DDTrace\close_span();
    $root = json_decode($rr->waitForDataAndReplay()["body"], true);
    $spans = $root["chunks"][0]["spans"] ?? $root[0];
    $sampling = $spans[0]["metrics"]["_sampling_priority_v1"];
    dd_trace_internal_fn("synchronous_flush");

    if($sampling == 1 && $picked == 1) //Start again, probably different minute
    {
        $notPicked = 0;
        continue;
    } else if ($sampling == 1) { //First picked
        $picked = 1;
    } else if ($sampling == 0) {
       $notPicked++;
    } else if($picked == 0 && $sampling == 0) {
        //If this happen means something is odd already
        break;
    }
    if ($picked == 1 && $notPicked == 2) {
        break;
    }
}

if ($picked == 1 && $notPicked == 2) {
    echo "All good" . PHP_EOL;
}

echo "Done" . PHP_EOL;

?>
--EXPECTF--
All good
Done