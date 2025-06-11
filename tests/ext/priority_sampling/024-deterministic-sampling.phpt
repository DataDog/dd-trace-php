--TEST--
run sampling algorithm on multiple trace IDs and ensure that the results are deterministic
--ENV--
DD_TRACE_SAMPLE_RATE=0.5
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php

$trace_ids = [
    ["9223372036854775808", true], // lands exactly on the threshold
    // Test random very large traceIDs
    ["1", true],
    ["10", false],
    ["100", true],
    ["1000", true],
    ["18444899399302180861", false],
    // Test random very large traceIDs
    ["18444899399302180860", false],
    ["18444899399302180861", false],
    ["18444899399302180862", true],
    ["18444899399302180863", true],
    // Test boundary values
    ["18446744073709551615", false], // 2**64-1
    ["9223372036854775809", false], // 2**63+1
    ["9223372036854775807", true], // 2**63-1
    ["4611686018427387905", false], // 2**62+1
    ["4611686018427387903", false], // 2**62-1
    // Random traceIDs
    ["646771306295669658", true],
    ["1882305164521835798", true],
    ["5198373796167680436", false],
    ["6272545487220484606", true],
    ["8696342848850656916", true],
    ["10197320802478874805", true],
    ["10350218024687037124", true],
    ["12078589664685934330", false],
    ["13794769880582338323", true],
    ["14629469446186818297", false]
];



foreach ($trace_ids as $trace_id_and_expected_sampling) {
    $trace_id = $trace_id_and_expected_sampling[0];
    $expected_sampling = $trace_id_and_expected_sampling[1] ? 2 : -1;

    $root = \DDTrace\root_span();

    DDTrace\consume_distributed_tracing_headers(function ($header) use ($trace_id) {
        return [
             "x-datadog-trace-id" => $trace_id,
             "x-datadog-parent-id" => "1234567890",
            ][$header] ?? null;
    });

    \DDTrace\get_priority_sampling();

    printf("sampling for trace %s is %d expected %d\n", $trace_id, $root->samplingPriority, $expected_sampling);
    // The decision maker tag is not applied when the trace is not sampled,
    // we should check if this is the case in other tracers.
    printf("_dd.p.dm = %s\n", $root->meta["_dd.p.dm"] ?? "-");
}

?>
--EXPECT--
sampling for trace 9223372036854775808 is 2 expected 2
_dd.p.dm = -3
sampling for trace 1 is 2 expected 2
_dd.p.dm = -3
sampling for trace 10 is -1 expected -1
_dd.p.dm = -
sampling for trace 100 is 2 expected 2
_dd.p.dm = -3
sampling for trace 1000 is 2 expected 2
_dd.p.dm = -3
sampling for trace 18444899399302180861 is -1 expected -1
_dd.p.dm = -
sampling for trace 18444899399302180860 is -1 expected -1
_dd.p.dm = -
sampling for trace 18444899399302180861 is -1 expected -1
_dd.p.dm = -
sampling for trace 18444899399302180862 is 2 expected 2
_dd.p.dm = -3
sampling for trace 18444899399302180863 is 2 expected 2
_dd.p.dm = -3
sampling for trace 18446744073709551615 is -1 expected -1
_dd.p.dm = -
sampling for trace 9223372036854775809 is -1 expected -1
_dd.p.dm = -
sampling for trace 9223372036854775807 is 2 expected 2
_dd.p.dm = -3
sampling for trace 4611686018427387905 is -1 expected -1
_dd.p.dm = -
sampling for trace 4611686018427387903 is -1 expected -1
_dd.p.dm = -
sampling for trace 646771306295669658 is 2 expected 2
_dd.p.dm = -3
sampling for trace 1882305164521835798 is 2 expected 2
_dd.p.dm = -3
sampling for trace 5198373796167680436 is -1 expected -1
_dd.p.dm = -
sampling for trace 6272545487220484606 is 2 expected 2
_dd.p.dm = -3
sampling for trace 8696342848850656916 is 2 expected 2
_dd.p.dm = -3
sampling for trace 10197320802478874805 is 2 expected 2
_dd.p.dm = -3
sampling for trace 10350218024687037124 is 2 expected 2
_dd.p.dm = -3
sampling for trace 12078589664685934330 is -1 expected -1
_dd.p.dm = -
sampling for trace 13794769880582338323 is 2 expected 2
_dd.p.dm = -3
sampling for trace 14629469446186818297 is -1 expected -1
_dd.p.dm = -