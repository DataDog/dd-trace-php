--TEST--
An end already owed to a suspended generator survives another invocation's callback
--DESCRIPTION--
The recursion guard stops a hook re-entering its own line, and it is definition-wide.
That is right for begins, but an end belongs to a range instance whose begin was already delivered: suppressing it drops a cleanup the API promises.
A suspended generator makes the two separable, because its owed end can come due while an unrelated invocation's callback is running.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: LineHookData::var() on a suspended generator needs PHP 8');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function generator_target($label) {
    $a = 1;                                 // 4  begin
    yield $a;
    return 2;                               // 6  end
}

$log = [];
$pending = null;
$id = DDTrace\install_line_hook(__FILE__, 4, function (DDTrace\LineHookData $h) use (&$log, &$pending) {
    $label = $h->var('label');
    $log[] = 'begin:' . $label;
    if ($label === 'outer') {
        $pending->next();                   // drives pending's owed end from inside outer's begin
    }
}, 6, function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = 'end:' . $h->var('label');
});
var_dump($id < 0);

$pending = generator_target('pending');
$pending->current();                        // begin delivered, then suspended at the yield

$outer = generator_target('outer');
$outer->current();
$outer->next();

echo implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
bool(true)
begin:pending,begin:outer,end:pending,end:outer
Done.
