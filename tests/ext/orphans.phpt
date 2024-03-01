--TEST--
Orphans Removal with empty agent sample rate
--SKIPIF--
<?php include __DIR__ . '/includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS=1
DD_TRACE_AGENT_RETRIES=3
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';

function handleOrphan(\DDTrace\SpanData $span)
{
    if (dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
        && $span instanceof \DDTrace\RootSpanData
        && empty($span->parentId)
    ) {
        $prioritySampling = \DDTrace\get_priority_sampling();
        if ($prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP
            || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_USER_KEEP
            || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT
        ) {
            \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
        }
    }
}

function foo () {
    //
}

\DDTrace\trace_function("foo", function (\DDTrace\SpanData $span) {
    handleOrphan($span);
});

$rr = new RequestReplayer();

$get_sampling = function() use ($rr) {
    $replay = $rr->replayRequest();
    if (!isset($replay["body"])) {
        return null;
    }

    $root = json_decode($replay["body"], true);
    $spans = $root["chunks"][0]["spans"] ?? $root[0];
    return $spans[0]["metrics"]["_sampling_priority_v1"];
};

// To prevent flakiness associated with the request not managing to getting replayed,
// we'll just check for a sampling different from zero, and ignore if it wasn't retrievable

$threshold = 0.5;
$totalRequests = 20;
$emptyReplays = 0;
$valid = 0;

for ($i = 0; $i < $totalRequests; $i++) {
    $rr->setResponse(["rate_by_service" => ["service:,env:" => rand(0, 1)]]);
    foo();
    $rr->waitForFlush();
    $sampling = $get_sampling();
    if ($sampling !== null) {
        if ($sampling === 0) {
            $valid++;
        } else {
            echo "NOK: Sampling different from zero\n"; // We should never have a sampling different from zero
        }
    } else {
        $emptyReplays++;
    }
}

if ($emptyReplays > $threshold * $totalRequests) {
    echo "NOK: Too many empty requests - $emptyReplays/$totalRequests\n";
} else {
    echo "OK\n";
}

?>
--EXPECT--
OK
