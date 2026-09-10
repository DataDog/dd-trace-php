--TEST--
Inherited OpenTelemetry tracestate sampling fields are validated and forwarded
--ENV--
DD_TRACE_SAMPLE_RATE=0.5
DD_TRACE_RATE_LIMIT=10000000
DD_TRACE_SAMPLING_RULES=[{"sample_rate":0.1,"service":"locally-decided","target_span":"any"}]
--FILE--
<?php

function propagate(string $tracestate, bool $sampled = true, bool $manualKeep = false): string
{
    $span = DDTrace\start_span();
    DDTrace\consume_distributed_tracing_headers([
        'traceparent' => '00-0000000000000000fff972474538efff-0000000000000001-0' . ($sampled ? '1' : '0'),
        'tracestate' => $tracestate,
    ]);
    if ($manualKeep) {
        DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);
    }

    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    DDTrace\close_span();
    return $headers['tracestate'];
}

function ot(string $tracestate): string
{
    return preg_match('/(?:^|,)ot=([^,]+)/', $tracestate, $matches) ? $matches[1] : '<absent>';
}

function locallyDecide(string $tracestate): string
{
    $span = DDTrace\start_span();
    DDTrace\consume_distributed_tracing_headers([
        'traceparent' => '00-0000000000000000fff972474538efff-0000000000000001-00',
        'tracestate' => $tracestate,
    ]);
    $span->service = 'locally-decided';

    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    DDTrace\close_span();
    return $headers['tracestate'];
}

echo ot(propagate('dd=s:2;t.dm:-3,ot=rv:ef284ace7a91e1;th:e6666666666668;foo:bar')), PHP_EOL;
echo ot(propagate('ot=th:e6666666666668')), PHP_EOL;
echo ot(propagate('dd=s:0,ot=rv:ef284ace7a91e1;th:e6666666666668', false)), PHP_EOL;
echo ot(propagate('dd=s:1,ot=rv:not-hex;th:not-hex,congo=xyz123')), PHP_EOL;
echo ot(propagate('ot=rv:1234567890abcd;th:not-hex')), PHP_EOL;
echo ot(propagate('dd=s:1')), PHP_EOL;
echo ot(propagate('ot=foo:bar')), PHP_EOL;
echo ot(propagate('ot=rv:65cd67504a538e;th:e6666666666668', false, true)), PHP_EOL;
echo ot(propagate('', false, true)), PHP_EOL;

$ordered = propagate('dd=s:1,foo=bar,ot=rv:6e6d1a75832a2f,something=else');
echo substr($ordered, strpos($ordered, ',') + 1), PHP_EOL;

echo ot(locallyDecide('ot=rv:00000000000000;th:f0000000000000;foo:bar')), PHP_EOL;
echo ot(locallyDecide('ot=rv:00000000000000;foo:bar')), PHP_EOL;
echo ot(locallyDecide('ot=th:f0000000000000;foo:bar')), PHP_EOL;

?>
--EXPECT--
rv:ef284ace7a91e1;th:e6666666666668;foo:bar
th:e6666666666668
rv:ef284ace7a91e1;th:e6666666666668
<absent>
rv:1234567890abcd
<absent>
foo:bar
rv:65cd67504a538e
<absent>
foo=bar,ot=rv:6e6d1a75832a2f,something=else
rv:ef284ace7a91e1;th:e6666666666668;foo:bar
rv:ef284ace7a91e1;th:e6666666666668;foo:bar
rv:ef284ace7a91e1;th:e6666666666668;foo:bar
