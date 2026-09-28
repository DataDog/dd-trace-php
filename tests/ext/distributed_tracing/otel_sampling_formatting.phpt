--TEST--
OpenTelemetry field formatting preserves 56-bit values and per-member limits
--FILE--
<?php

function formatted(string $value): string
{
    $link = DDTrace\SpanLink::fromHeaders([
        'traceparent' => '00-0000000000000000000000000000002a-0000000000000001-01',
        'tracestate' => 'ot=' . $value,
    ]);
    preg_match('/(?:^|,)ot=([^,]+)/', $link->traceState ?? '', $matches);
    return $matches[1] ?? '<absent>';
}

foreach ([
    '',
    'rv:00000000000000;th:0',
    'rv:ffffffffffffff;th:ffffffffffffff',
    'rv:ffffffffffffff',
    'th:00000000000001',
    'th:8',
    'th:80000000000000',
    'th:08',
    'th:00000000000000',
    'rv:00000000000000',
    'rv:00000000000001',
    'rv:ffffffffffffffff;th:ffffffffffffffff',
    'rv:invalid;rv:00000000000000;th:invalid;th:0',
    'foo:bar;next:value',
    'rv:fffffffffffffff;th:fffffffffffffff;foo:bar',
    'rv:1234567890abcd;rv:invalid;th:8;th:invalid',
] as $value) {
    echo formatted($value), PHP_EOL;
}

$unknown = 'foo:' . str_repeat('x', 252);
echo 'unknown-only bytes: ', strlen(formatted($unknown)), PHP_EOL;
$prefix = 'rv:ffffffffffffff;th:ffffffffffffff;';
$unknown = 'foo:' . str_repeat('x', 216);
echo 'full member bytes: ', strlen(formatted($prefix . $unknown)), PHP_EOL;
echo 'oversized field skipped: ', formatted($prefix . $unknown . 'x;next:value'), PHP_EOL;
?>
--EXPECT--
<absent>
rv:00000000000000;th:0
rv:ffffffffffffff;th:ffffffffffffff
rv:ffffffffffffff
th:00000000000001
th:8
th:8
th:08
th:0
rv:00000000000000
rv:00000000000001
<absent>
rv:00000000000000;th:0
foo:bar;next:value
foo:bar
<absent>
unknown-only bytes: 256
full member bytes: 256
oversized field skipped: rv:ffffffffffffff;th:ffffffffffffff;next:value
