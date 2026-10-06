--TEST--
Exceptions from line hook captures unwind unfinished calls and expression temporaries
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
class CaptureThrows {
    public function __destruct() { throw new RuntimeException('capture'); }
}
class TemporaryValue {
    public function __destruct() { echo "temporary destroyed\n"; }
}
function sink(...$args) { echo "unexpected call\n"; }
function concat($x = 'x') {
    return 'prefix' . $x;
}
function rope($x = 'x', $y = 'y') {
    return "$x $y $x";
}
function rope_first() {
    return "$x $y $x";
}
function first_array_expression() {
    return [$x, $y];
}
function call_argument() {
    return sink(
        new TemporaryValue,
        2
    );
}
function array_element() {
    return [
        new TemporaryValue,
        2
    ];
}
foreach (['concat' => 1, 'rope' => 1, 'rope_first' => 1, 'first_array_expression' => 1, 'call_argument' => 3, 'array_element' => 3] as $function => $offset) {
    echo "$function\n";
    $victim = new CaptureThrows;
    $line = (new ReflectionFunction($function))->getStartLine() + $offset;
    $id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use ($victim) { DDTrace\remove_hook($h->id); });
    unset($victim);
    try {
        $function();
        echo "unexpected return\n";
    } catch (Throwable $e) {
        echo 'caught: ', $e->getMessage(), "\n";
    }
    unset($e);
    DDTrace\remove_hook($id);
}
echo "Done.\n";
?>
--EXPECT--
concat
caught: capture
rope
caught: capture
rope_first
caught: capture
first_array_expression
caught: capture
call_argument
temporary destroyed
caught: capture
array_element
temporary destroyed
caught: capture
Done.
