--TEST--
Test creating swapping traces
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

DDTrace\switch_stack();
echo 'We are back on our primary stack: '; var_dump($primary_trace == DDTrace\active_span());
echo 'Having lost all references to the that span stacks objects, it is autoclosed: '; var_dump($weakref->get()->getDuration() !== 0);

# close primary trace span again
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECT--