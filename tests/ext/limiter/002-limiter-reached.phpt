--TEST--
rate limiter reached
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_RATE_LIMIT=10
--FILE--
<?php
$spans = [];
$sampled = 0;
$loopBreak = 1000;

while (true) {
    \DDTrace\start_span();
    \DDTrace\close_span();

    $spans = array_merge($spans, \dd_trace_serialize_closed_spans());
    $sampled = 0;

    foreach ($spans as $span) {
        if (isset($span["metrics"]["_sampling_priority_v1"])) {
            $sampled++;
        }
    }

    if ($sampled > 20) {
        break;
    }

    if (--$loopBreak < 0) {
        echo "No 20 spans were sampled.\n";
        break; # avoid infinite loop with DD_TRACE_ENABLED=0
    }
}

$end = $spans[\count($spans)-1];

if (\round($end["metrics"]["_dd.limit_psr"], 1) != 0.5) {
    echo "Fail\n";
    var_dump($spans);
    exit;
}

echo "OK";
?>
--EXPECT--
OK
