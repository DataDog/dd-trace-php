--TEST--
Test autoclosing of spans on abandoned span stacks
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip: Requires PHP 7.4 or greater to observe using WeakRefs'); ?>
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

$primary_trace = DDTrace\start_span();

DDTrace\create_stack();

$weakref = WeakReference::create(DDTrace\start_span());

gc_collect_cycles(); // early collection has no side-effect (like crashes)

DDTrace\switch_stack();
gc_collect_cycles(); // force immediate collection

echo 'We are back on our primary stack: '; var_dump($primary_trace == DDTrace\active_span());
echo 'Having lost all references to the that span stacks objects, it is autoclosed: '; var_dump($weakref->get()->getDuration() !== 0);

# close primary trace span again
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECTF--
We are back on our primary stack: bool(true)
Having lost all references to the that span stacks objects, it is autoclosed: bool(true)
spans(\DDTrace\SpanData) (1) {
  span_trace_stack_autoclose.php (span_trace_stack_autoclose.php, span_trace_stack_autoclose.php, cli)
    process_id => %d
    _dd.p.dm => -1
     (span_trace_stack_autoclose.php, cli)
}
