--TEST--
Serialization snapshots trace sampling across sibling spans and attached stacks
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"name":"keep*","sample_rate":1}]
DD_TRACE_STATS_COMPUTATION_ENABLED=0
--FILE--
<?php
include __DIR__ . '/../sandbox/dd_dumper.inc';

class ChangeSamplingDuringSerialization {
    public function __destruct() {
        ini_set('datadog.trace.sample_rate', '1');
    }
}

$root = DDTrace\start_span();
$root->name = 'root';
// With a propagated priority the sampling decision keeps _dd.p.dm: serialization drops it from the root span,
// releasing this object mid-chunk.
DDTrace\consume_distributed_tracing_headers(['x-datadog-trace-id' => '1', 'x-datadog-parent-id' => '2', 'x-datadog-sampling-priority' => '0']);
$root->attributes['_dd.p.dm'] = new ChangeSamplingDuringSerialization();

DDTrace\start_span()->name = 'keep.child';
DDTrace\close_span();
DDTrace\create_stack();
DDTrace\start_span()->name = 'keep.attached';
DDTrace\close_span();
DDTrace\switch_stack($root);
DDTrace\start_span()->name = 'unmatched';
DDTrace\close_span();
DDTrace\close_span();

foreach (dd_clean_spans() as $span) {
    echo $span['name'], ': ', isset($span['attributes']['_dd.span_sampling.mechanism']) ? 'single span' : 'trace', "\n";
}

// Subsequent chunks must observe the new configuration.
DDTrace\start_span()->name = 'keep.next';
DDTrace\close_span();
$chunk = dd_trace_serialize_closed_spans()['chunks'][0];
$span = $chunk['spans'][0];
echo $span['name'], ': ', isset($span['attributes']['_dd.span_sampling.mechanism']) ? 'single span' : 'trace', "\n";
var_dump($chunk['priority']);
var_dump(dd_trace_serialize_closed_spans()['chunks']);

?>
--EXPECT--
root: trace
unmatched: trace
keep.child: single span
keep.attached: single span
keep.next: trace
int(2)
array(0) {
}
