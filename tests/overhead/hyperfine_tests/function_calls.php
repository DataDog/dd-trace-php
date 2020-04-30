<?php

function sample_test($val, $add)
{
    return $val + $add;
}
$val = 0;


if ($argc > 1 && $argv[1] == "trace_function") {
    \dd_trace_function('sample_test', function (SpanData $span, $args, $result) use ($val) {
        $span->name  = "sample_test";
        $span->type = "webb";
        $span->service = "svc";
        $val++;
    });
}

for ($i = 0; $i < 10000000; $i++) {
    $val = sample_test($val, 1);
}

echo $val . "\n";
