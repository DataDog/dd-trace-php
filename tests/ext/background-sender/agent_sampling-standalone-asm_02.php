<?php
include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

function getSecondsLeftOnCurrentMinute() {
    $nowInSeconds = explode(' ', microtime())[1];
    return 60 - intval($nowInSeconds % 60);
}

if (getSecondsLeftOnCurrentMinute() < 5) {
    //Lets make sure all calls are within the same minute
    sleep(6);
}

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
DDTrace\Testing\emit_asm_event();
\DDTrace\close_span();
echo "This call has the same sample rate: {$get_sampling()}\n";

// reset it for other tests
dd_trace_internal_fn("synchronous_flush");

\DDTrace\start_span();
DDTrace\Testing\emit_asm_event();
\DDTrace\close_span();
echo "This call also has the same sample rate: {$get_sampling()}\n";

?>
