--TEST--
Ingest all spans
--ENV--
DD_SAMPLING_RATE=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$tests = [
    ["a", "a"],
    ["a**", "a"],
    ["**a", "a"],
    ["*", "a"],
    [".", "a"],
    ["..", "a"],
    ["*.", "a"],
    [".*", "a"],
    ["a*a*b", "aaaacaab"],
    ["a*a*b", "aaabbb"],
    ["a*a*b.", "aaaacaab"],
];

foreach ($tests as [$pattern, $service]) {
    ini_set("datadog.span_sampling_rules", '[{"service":"' . $pattern . '","sample_rate":1}]');

    DDTrace\start_span()->service = $service;
    DDTrace\close_span();
    echo "$pattern matches $service (service): ";
    var_dump((dd_trace_serialize_closed_spans()[0]["metrics"]["_dd.span_sampling.mechanism"] ?? 0) == 8);

    ini_set("datadog.span_sampling_rules", '[{"name":"' . $pattern . '","sample_rate":1}]');

    DDTrace\start_span()->name = $service;
    DDTrace\close_span();
    echo "$pattern matches $service (name): ";
    var_dump((dd_trace_serialize_closed_spans()[0]["metrics"]["_dd.span_sampling.mechanism"] ?? 0) == 8);
}

?>
--EXPECT--
a matches a (service): bool(true)
a matches a (name): bool(true)
a** matches a (service): bool(true)
a** matches a (name): bool(true)
**a matches a (service): bool(true)
**a matches a (name): bool(true)
* matches a (service): bool(true)
* matches a (name): bool(true)
. matches a (service): bool(true)
. matches a (name): bool(true)
.. matches a (service): bool(false)
.. matches a (name): bool(false)
*. matches a (service): bool(true)
*. matches a (name): bool(true)
.* matches a (service): bool(false)
.* matches a (name): bool(false)
a*a*b matches aaaacaab (service): bool(true)
a*a*b matches aaaacaab (name): bool(true)
a*a*b matches aaabbb (service): bool(true)
a*a*b matches aaabbb (name): bool(true)
a*a*b. matches aaaacaab (service): bool(false)
a*a*b. matches aaaacaab (name): bool(false)
