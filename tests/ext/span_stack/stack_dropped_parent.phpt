--TEST--
Span stacks are fully reset when the tracer is disabled and re-enabled
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

DDTrace\start_span();
$stack = DDTrace\create_stack();

ini_set("datadog.trace.enabled", 0);
ini_set("datadog.trace.enabled", 1);

DDTrace\switch_stack($stack);
DDTrace\start_span();
DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
var_dump(count($spans));
var_dump(isset($spans[0]["parent_id"]));

?>
--EXPECT--
int(1)
bool(false)
