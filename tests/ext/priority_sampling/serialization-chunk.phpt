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

class ChangeSamplingDuringSerialization {
    public function __destruct() {
        ini_set('datadog.trace.sample_rate', '1');
    }
}

$root = DDTrace\start_span();
$root->name = 'root';
// Serialization converts this metric to a string tag and releases the old value.
$root->metrics['http.status_code'] = new ChangeSamplingDuringSerialization();

DDTrace\start_span()->name = 'keep.child';
DDTrace\close_span();
DDTrace\create_stack();
DDTrace\start_span()->name = 'keep.attached';
DDTrace\close_span();
DDTrace\switch_stack($root);
DDTrace\start_span()->name = 'unmatched';
DDTrace\close_span();
DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans() as $span) {
    echo $span['name'], ': ', isset($span['metrics']['_dd.span_sampling.mechanism']) ? 'single span' : 'trace', "\n";
}

// Subsequent chunks must observe the new configuration.
DDTrace\start_span()->name = 'keep.next';
DDTrace\close_span();
$span = dd_trace_serialize_closed_spans()[0];
echo $span['name'], ': ', isset($span['metrics']['_dd.span_sampling.mechanism']) ? 'single span' : 'trace', "\n";
var_dump($span['metrics']['_sampling_priority_v1']);
var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECT--
root: trace
unmatched: trace
keep.child: single span
keep.attached: single span
keep.next: trace
float(2)
array(0) {
}
