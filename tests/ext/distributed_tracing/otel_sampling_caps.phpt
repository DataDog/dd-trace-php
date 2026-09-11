--TEST--
OpenTelemetry tracestate sampling honors value, member, and byte caps
--ENV--
DD_TRACE_SAMPLE_RATE=0.5
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

function headersWithTracestate(string $tracestate): array
{
    $span = DDTrace\start_span();
    $root = DDTrace\root_span();
    $root->traceId = str_pad('1', 32, '0', STR_PAD_LEFT);
    $root->tracestate = $tracestate;
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    DDTrace\close_span();
    return $headers;
}

$vendors = [];
for ($i = 0; $i < 32; ++$i) {
    $vendors[] = "vendor{$i}=value";
}
$tracestate = headersWithTracestate(implode(',', $vendors))['tracestate'];
$members = explode(',', $tracestate);
echo 'members=', count($members), ' leading=', implode(',', array_map(
    function (string $member): string {
        return strstr($member, '=', true);
    },
    array_slice($members, 0, 2)
)), PHP_EOL;

$largeVendors = [];
for ($i = 0; $i < 32; ++$i) {
    $largeVendors[] = "vendor{$i}=" . str_repeat('x', 30);
}
$tracestate = headersWithTracestate(implode(',', $largeVendors))['tracestate'];
echo 'bytes=', strlen($tracestate) <= 512 ? 'within-cap' : 'over-cap',
    ' complete=', substr($tracestate, -1) === 'x' ? 'yes' : 'no', PHP_EOL;

$largeDatadog = 'dd=p:0000000000000001;t.large:' . str_repeat('x', 470);
$tracestate = headersWithTracestate($largeDatadog)['tracestate'];
$members = explode(',', $tracestate);
echo 'owned-bytes=', strlen($tracestate) <= 512 ? 'within-cap' : 'over-cap',
    ' leading=', implode(',', array_map(
        function (string $member): string {
            return strstr($member, '=', true);
        },
        array_slice($members, 0, 2)
    )),
    ' large=', strpos($tracestate, 't.large:') === false ? 'dropped' : 'kept', PHP_EOL;

$oversizedUnknown = 'future:' . str_repeat('x', 230) . ';next:value';
$tracestate = headersWithTracestate('ot=' . $oversizedUnknown)['tracestate'];
preg_match('/(?:^|,)ot=([^,]+)/', $tracestate, $matches);
echo 'ot-bytes=', strlen($matches[1]),
    ' future=', strpos($matches[1], 'future:') === false ? 'dropped' : 'kept',
    ' next=', strpos($matches[1], 'next:value') === false ? 'dropped' : 'kept', PHP_EOL;

?>
--EXPECTF--
members=32 leading=dd,ot
bytes=within-cap complete=yes
owned-bytes=within-cap leading=dd,ot large=dropped
ot-bytes=33 future=dropped next=kept
