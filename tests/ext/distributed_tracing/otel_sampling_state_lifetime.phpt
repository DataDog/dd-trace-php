--TEST--
Optional OpenTelemetry sampling fields survive copying and context replacement
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_PROPAGATION_STYLE_EXTRACT=datadog,tracecontext
--FILE--
<?php

function headers(string $fields): array
{
    return [
        'x-datadog-trace-id' => '42',
        'x-datadog-parent-id' => '1',
        'x-datadog-sampling-priority' => '1',
        'traceparent' => '00-0000000000000000000000000000002a-0000000000000001-01',
        'tracestate' => 'ot=rv:1234567890abcd;th:8;' . $fields,
    ];
}

function report()
{
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    preg_match('/(?:^|,)ot=[^,]*?(foo:[^;,]+)/', $headers['tracestate'], $matches);
    echo $matches[1] ?? '<absent>', PHP_EOL;
}

// Store state globally before any root exists, including the Datadog/W3C merge.
DDTrace\consume_distributed_tracing_headers(headers('foo:global'));
$root = DDTrace\start_span();
report();
DDTrace\consume_distributed_tracing_headers(headers('foo:replacement'));
report();

// A nested trace owns a reference independently of its parent.
$nested = DDTrace\start_trace_span();
$root->tracestate = 'ot=rv:1234567890abcd;th:8;foo:parent';
report();
$nested->tracestate = 'ot=rv:1234567890abcd;th:8;foo:nested';
report();
DDTrace\close_span();
DDTrace\switch_stack($root);
report();
DDTrace\close_span();
unset($nested, $root);
dd_trace_serialize_closed_spans();
gc_collect_cycles();

// Releasing roots and links must not release the global state's reference.
$link = DDTrace\SpanLink::fromHeaders(headers('foo:link'));
echo strpos($link->traceState, 'foo:link') !== false ? "link retained\n" : "link lost\n";
unset($link);
DDTrace\start_span();
report();
DDTrace\root_span()->tracestate = 'vendor=value';
report();
DDTrace\root_span()->tracestate = 'ot=foo:only';
echo 'empty vendors: ', DDTrace\root_span()->tracestate === '' ? 'yes' : 'no', PHP_EOL;
report();
DDTrace\root_span()->tracestate = '';
DDTrace\root_span()->tracestate = '';
report();
DDTrace\close_span();

// Replace global state as well as root-local state.
DDTrace\consume_distributed_tracing_headers(headers('foo:new-global'));
DDTrace\start_span();
report();
DDTrace\close_span();
?>
--EXPECT--
foo:global
foo:replacement
foo:replacement
foo:nested
foo:parent
link retained
foo:global
<absent>
empty vendors: yes
foo:only
<absent>
foo:new-global
