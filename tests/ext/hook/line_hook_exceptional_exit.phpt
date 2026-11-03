--TEST--
A range left by an exception closes before the catch or finally block runs
--DESCRIPTION--
dd_line_each_jump_target() enumerates only the jumps the compiler encoded, so an exception leaving the range has no static exit site and the range stayed open until frame exit -- which put the end *after* the catch/finally work and folded that work into the measured range.
The public contract is an immediate end when control skips out of the range, so the exceptional successors in op_array->try_catch_array have to be exit sites too.
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

function with_catch() {
    try {
        $b = 1;                             // 14 begin
        throw new RuntimeException('bang'); // 15 end line
    } catch (RuntimeException $e) {
        echo "  catch\n";                   // 17 outside the range
    }
    return 'done';                          // 19
}

// Echoed inline rather than collected: the whole point is where the end lands relative to the handler block.
$mk = function ($tag) {
    return function (DDTrace\LineHookData $h) use ($tag) { echo "  $tag@{$h->line}\n"; };
};

echo "finally:\n";
$f = DDTrace\install_line_hook(__FILE__, 5, $mk('Fb'), 6, $mk('Fe'));
var_dump($f < 0);
try {
    with_finally();
} catch (RuntimeException $e) {
    echo "  caught: ", $e->getMessage(), "\n";
}
DDTrace\remove_hook($f);

echo "catch:\n";
$c = DDTrace\install_line_hook(__FILE__, 14, $mk('Cb'), 15, $mk('Ce'));
var_dump($c < 0);
var_dump(with_catch());
DDTrace\remove_hook($c);

echo "Done.\n";
?>
--EXPECT--
finally:
bool(true)
  Fb@5
  Fe@8
  finally
  caught: boom
catch:
bool(true)
  Cb@14
  Ce@16
  catch
string(4) "done"
Done.
