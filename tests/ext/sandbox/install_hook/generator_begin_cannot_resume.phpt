--TEST--
A generator cannot be resumed from its own begin callback, and still runs normally afterwards
--DESCRIPTION--
A generator's begin callbacks receive the generator itself as HookData::$returned, so that overrideReturnValue() can replace it.
Nothing stopped a callback from *running* it instead, and every way of doing so corrupted the frame's bookkeeping: resuming it re-entered a frame whose record is not published yet, so line dispatch there joined and filed a record of its own which the creation insert then overwrote -- losing its open ranges and hook references -- and running it to completion left execute_data NULL under a record that was published anyway, which the destructor dereferenced.

The engine already has an interlock for "this generator may not be entered now", so creation holds ZEND_GENERATOR_CURRENTLY_RUNNING across the callbacks and zend_generator_resume() refuses.
Clearing it also clears ZEND_GENERATOR_AT_FIRST_YIELD, because zend_generator_ensure_initialized() sets that flag straight after calling resume() without checking whether the resume happened -- leaving a generator that never ran marked as sitting at its first yield, which would let a later rewind() wrongly succeed.
--SKIPIF--
<?php
// HookData::$returned is only populated for generator begin hooks on PHP 8 (uhook.c, #if PHP_VERSION_ID >= 80000).
if (PHP_VERSION_ID < 80000) die('skip: generator begin hooks expose $returned only on PHP 8');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.hook_limit=0
--FILE--
<?php
function gen() { yield 1; return 2; }

DDTrace\install_hook('gen', function ($h) {
    foreach (['current', 'next', 'rewind'] as $method) {
        try { $h->returned->$method(); echo "$method: NOT REFUSED\n"; }
        catch (Throwable $e) { echo "$method: ", get_class($e), ": ", $e->getMessage(), "\n"; }
    }
    try { iterator_to_array($h->returned); echo "consume: NOT REFUSED\n"; }
    catch (Throwable $e) { echo "consume: ", get_class($e), ": ", $e->getMessage(), "\n"; }
}, function () { echo "end\n"; });

// The refusals must leave the generator untouched: it runs, completes and reports its end normally.
$g = gen();
foreach ($g as $v) { echo "yielded $v\n"; }
var_dump($g->getReturn());
echo "Done.\n";
?>
--EXPECT--
current: Error: Cannot resume an already running generator
next: Error: Cannot resume an already running generator
rewind: Error: Cannot resume an already running generator
consume: Error: Cannot resume an already running generator
yielded 1
end
int(2)
Done.
