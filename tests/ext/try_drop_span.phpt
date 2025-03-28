--TEST--
Test try_drop_span()
--FILE--
<?php

$span = DDTrace\start_span();
var_dump(DDTrace\try_drop_span($span));

$span = DDTrace\start_span();
$span->name = "root span with child span retained";
DDTrace\start_span()->name = "child span";
DDTrace\close_span();
var_dump(DDTrace\try_drop_span($span));
DDTrace\close_span();

$span = DDTrace\start_span();
DDTrace\create_stack();
var_dump(DDTrace\try_drop_span($span));
DDTrace\start_span()->name = "retained on dropped stack";
DDTrace\close_span();
DDTrace\switch_stack();

$span = DDTrace\start_span();
$span->name = "root span with child stack retained";
DDTrace\create_stack();
DDTrace\start_span()->name = "retained on dropped stack";
var_dump(DDTrace\try_drop_span($span));
DDTrace\close_span();
DDTrace\switch_stack();
DDTrace\close_span();

$span = DDTrace\start_span();
$span->name = "root span dropped after child span dropped";
DDTrace\create_stack();
$childSpan = DDTrace\start_span();
$childSpan->name = "dropped on stack";
var_dump(DDTrace\try_drop_span($childSpan));
var_dump(DDTrace\try_drop_span($span));
DDTrace\switch_stack();

$span = DDTrace\start_span();
$span->name = "distributed tracing headers inhibit dropping";
DDTrace\generate_distributed_tracing_headers();
DDTrace\close_span();
var_dump(DDTrace\try_drop_span($span));

$span = DDTrace\start_span();
DDTrace\create_stack();
$span->name = "distributed tracing headers inhibit dropping, even on span stacks";
DDTrace\generate_distributed_tracing_headers();
var_dump(DDTrace\try_drop_span($span));
DDTrace\close_span();
DDTrace\switch_stack();

$span = DDTrace\start_span();
$span->name = "span link generation inhibits dropping";
$span->getLink();
DDTrace\close_span();
var_dump(DDTrace\try_drop_span($span));

foreach (dd_trace_serialize_closed_spans() as $span) {
    echo $span["name"], "\n";
}

?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
distributed tracing headers inhibit dropping, even on span stacks
distributed tracing headers inhibit dropping
root span with child stack retained
root span with child span retained
child span
