--TEST--
Setting custom distributed header information
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_TAGS=custom_tag=inherited,second_tag=bar
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function dump_context() {
    $context = DDTrace\current_context();
    echo "trace_id: {$context["trace_id"]}\n";
    echo "distributed_tracing_origin: " . (isset($context["distributed_tracing_parent_id"]) ? $context["distributed_tracing_origin"] : "<none>") . "\n";
    echo "distributed_tracing_parent_id: " . (isset($context["distributed_tracing_parent_id"]) ? $context["distributed_tracing_parent_id"] : "<none>") . "\n";
    echo "distributed_tracing_propagated_tags: " . json_encode($context["distributed_tracing_propagated_tags"]) . "\n";
}
dump_context();
DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]);
dump_context();
DDTrace\set_distributed_tracing_context("0", "0", "", [1 => "c"]);
dump_context();

?>
--EXPECT--
trace_id: 42
distributed_tracing_origin: datadog
distributed_tracing_parent_id: 10
distributed_tracing_propagated_tags: {"custom_tag":"inherited","second_tag":"bar"}
trace_id: 321
distributed_tracing_origin: foo
distributed_tracing_parent_id: 321
distributed_tracing_propagated_tags: {"a":"b"}
trace_id: 0
distributed_tracing_origin: <none>
distributed_tracing_parent_id: <none>
distributed_tracing_propagated_tags: []
