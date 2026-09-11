--TEST--
SpanLink::fromHeaders reapplies tracestate limits after rebuilding Datadog state
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE_EXTRACT=datadog,tracecontext
--FILE--
<?php

function report(array $members): void
{
    $link = DDTrace\SpanLink::fromHeaders([
        'x-datadog-trace-id' => '42',
        'x-datadog-parent-id' => '1',
        'x-datadog-tags' => '_dd.p.test=value',
        'traceparent' => '00-0000000000000000000000000000002a-0000000000000001-01',
        'tracestate' => implode(',', $members),
    ]);

    $tracestate = $link->traceState;
    $members = explode(',', $tracestate);
    $keys = array_map(
        function (string $member): string {
            return strstr($member, '=', true);
        },
        $members
    );

    echo 'members=', count($members),
        ' bytes=', strlen($tracestate) <= 512 ? 'within-cap' : 'over-cap',
        ' leading=', implode(',', array_slice($keys, 0, 2)),
        ' last=', end($keys), PHP_EOL;
}

$members = ['ot=rv:ef284ace7a91e1;th:8'];
for ($i = 0; $i < 31; ++$i) {
    $members[] = "vendor{$i}=value";
}
report($members);

$members = [];
for ($i = 0; $i < 32; ++$i) {
    $members[] = "vendor{$i}=value";
}
$members[] = 'ot=rv:ef284ace7a91e1;th:8';
report($members);

?>
--EXPECT--
members=32 bytes=within-cap leading=dd,ot last=vendor29
members=32 bytes=within-cap leading=dd,ot last=vendor29
