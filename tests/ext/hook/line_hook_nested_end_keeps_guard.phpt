--TEST--
Delivering an owed end from inside another callback does not unguard that callback
--DESCRIPTION--
Letting an already-owed end through the recursion guard is right, but the guard was one boolean cleared whenever any callback returned -- so the nested end unguarded the outer begin that was still running, and the target could then recurse into a begin the policy says to suppress.
A count of callbacks on the stack expresses the overlap directly, and is order independent where a saved-and-restored flag is not.
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
$depth = 0;
$id = DDTrace\install_line_hook(__FILE__, 4, function (DDTrace\LineHookData $h) use (&$log, &$pending, &$depth) {
    $label = $h->var('label');
    $log[] = 'begin:' . $label;
    if ($label === 'outer' && $depth++ === 0) {
        $pending->next();                   // delivers pending's owed end, mid-callback
        $g = generator_target('recursive'); // must still be suppressed: outer's begin is running
        $g->current();
    }
}, 6, function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = 'end:' . $h->var('label');
});
var_dump($id < 0);

$pending = generator_target('pending');
$pending->current();

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
