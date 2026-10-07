--TEST--
A line hook on a suspended, unhooked yield-from leaf refuses the implicit record instead of allocating into it
--DESCRIPTION--
A `yield from` leaf that carries no hooks of its own still gets a record, purely so its yields and resumptions are reported up to the observed parent.
That record has no hook payload, and the return and destruction paths delete it without calling zai_hook_finish().
Once the accessor started hiding implicit records, line dispatch stopped finding one and tried to join the frame instead -- and the join attached a payload to the implicit record, allocating a dynamic block and taking a hook reference that nothing would ever release.
Both interceptors now refuse the join, so the range is withheld rather than half-owned.
Unlike line_hook_implicit_yield_from.phpt this runs on PHP 7, which reaches the join through its own construction path.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.hook_limit=0
--FILE--
<?php
function leaf() {
    yield 1;
    $a = 2;
    return $a;
}
function outer() { yield from leaf(); }
DDTrace\install_hook('outer', function () { echo "outer begin\n"; }, function () { echo "outer end\n"; });
$g = outer();
$g->current();
$id = DDTrace\install_line_hook(__FILE__, 4, function () { echo "line begin\n"; }, 5, function () { echo "line end\n"; });
$g->next();
DDTrace\remove_hook($id);
echo "done\n";
?>
--EXPECT--
outer begin
outer end
done
