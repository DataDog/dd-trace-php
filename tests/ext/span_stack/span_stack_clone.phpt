--TEST--
Test cloning a span stack
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# primary trace
$primary_trace = DDTrace\start_span();
$primary_trace->name = "primary";

$new_root = DDTrace\start_trace_span();
$new_root->name = "root";
$clone = clone $new_root->stack;
DDTrace\close_span();

DDTrace\switch_stack($clone);
DDTrace\start_span()->name = "root clone";
DDTrace\close_span(); // clones of root stacks are also root stacks

echo "The parent stack of the clone is identical to the original stacks parent: "; var_dump(DDTrace\active_stack() == $primary_trace->stack);

$clone = clone $primary_trace->stack;
DDTrace\switch_stack($clone);
DDTrace\start_span()->name = "primary clone";
DDTrace\close_span();
echo "A clone of the primary trace has the root stack as parent: "; var_dump(DDTrace\active_span() == null);

# clone the initial stack
$clone = clone $primary_trace->stack->parent;
DDTrace\switch_stack($clone);
DDTrace\start_span()->name = "initial clone";
DDTrace\close_span();

# no-op, it's an initial stack
DDTrace\switch_stack();
echo "Switching to an initial stacks parent has no effect: "; var_dump(DDTrace\active_stack() == $clone);

# close spans on the primary trace
DDTrace\switch_stack($primary_trace);
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECTF--
The parent stack of the clone is identical to the original stacks parent: bool(true)
A clone of the primary trace has the root stack as parent: bool(true)
Switching to an initial stacks parent has no effect: bool(true)
spans(\DDTrace\SpanData) (5) {
  primary (span_stack_clone.php, primary, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  root (span_stack_clone.php, root, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  root clone (span_stack_clone.php, root clone, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  primary clone (span_stack_clone.php, primary clone, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  initial clone (span_stack_clone.php, initial clone, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
}
