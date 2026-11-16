--TEST--
A range left by an exception closes before the finally block runs
--DESCRIPTION--
dd_line_each_jump_target() enumerates only the jumps the compiler encoded, so an exception leaving the range had no static exit site and the range stayed open until frame exit -- putting the end *after* the finally work and folding that work into the measured range.
The exceptional successors in op_array->try_catch_array fix that.

The catch counterpart is line_hook_exceptional_exit.phpt.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function with_finally() {
    try {
        $a = 1;                             // 5  begin
        throw new RuntimeException('boom'); // 6  end line
    } finally {
        echo "  finally\n";                 // 8  outside the range
    }
}

$mk = function ($tag) {
    return function (DDTrace\LineHookData $h) use ($tag) { echo "  $tag@{$h->line}\n"; };
};

$f = DDTrace\install_line_hook(__FILE__, 5, $mk('Fb'), 6, $mk('Fe'));
var_dump($f < 0);
try {
    with_finally();
} catch (RuntimeException $e) {
    echo "  caught: ", $e->getMessage(), "\n";
}
DDTrace\remove_hook($f);

echo "Done.\n";
?>
--EXPECT--
bool(true)
  Fb@5
  Fe@8
  finally
  caught: boom
Done.
