<?php

class Sample
{
    function test($val, $add)
    {
        return $val + $add;
    }
}

function sample_test($val, $add)
{
    return $val + $add;
}

$val = 0;

if ($argc > 1 && $argv[1] == "trace_method") {
    \dd_trace_method('Sample', "test", function (SpanData $span, $args, $result) use ($val) {
        $span->name  = "sample_test";
        $span->type = "webb";
        $span->service = "svc";
        $val++;
    });
}

$obj = new Sample();
for ($i = 0; $i < 10000000; $i++) {
    $val = $obj->test($val, 1);
}

echo $val . "\n";
