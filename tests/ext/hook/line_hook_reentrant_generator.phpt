--TEST--
A begin suppressed by the recursion guard does not leave a range that later delivers an end
--DESCRIPTION--
Dispatch used to record the range as open before dd_line_call() applied the recursion guard.
Ordinary recursion hides that, because the nested end is suppressed too -- but a generator advanced from inside the callback can be resumed after the outer invocation has finished, and then delivers an end whose begin never ran.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function generator_target() {
    $a = 1;                                 // 4  begin
    yield $a;
    return 2;                               // 6  end
}

$log = [];
$nested = null;
$id = DDTrace\install_line_hook(__FILE__, 4, function () use (&$log, &$nested) {
    $log[] = 'begin';
    if (!$nested) {
        $nested = generator_target();       // its begin is suppressed by the recursion guard
        $nested->current();
    }
}, 6, function () use (&$log) {
    $log[] = 'end';
});
var_dump($id < 0);

$outer = generator_target();
$outer->current();
$outer->next();
$nested->next();                            // must not deliver an end of its own

echo implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
bool(true)
begin,end
Done.
