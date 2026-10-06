--TEST--
Line span cleanup propagates destructor exceptions after restoring the caller's stack
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
class SpanCleanupThrows {
    public function __destruct() {
        throw new RuntimeException('span cleanup escaped');
    }
}

function span_cleanup_target() {
    $value = 1;
    return $value;
}

$root = DDTrace\start_span();
$callerStack = DDTrace\active_stack();
$parentStack = DDTrace\create_stack();
$parent = DDTrace\start_span();
DDTrace\switch_stack($callerStack);
$line = (new ReflectionFunction('span_cleanup_target'))->getStartLine() + 1;

$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use ($parent) {
    $h->span($parent)->meta['cleanup'] = new SpanCleanupThrows();
}, $line, function () { return false; });
try {
    span_cleanup_target();
} catch (RuntimeException $e) {
    echo 'dropped span: ', $e->getMessage(), "\n";
}
var_dump(DDTrace\active_span() === $root, DDTrace\active_stack() === $callerStack);
DDTrace\remove_hook($id);

$id = DDTrace\install_line_hook(__FILE__, $line, null, $line, function ($h) {
    $h->span()->meta['cleanup'] = new SpanCleanupThrows();
});
try {
    span_cleanup_target();
} catch (RuntimeException $e) {
    echo 'dummy span: ', $e->getMessage(), "\n";
}
var_dump(DDTrace\active_span() === $root, DDTrace\active_stack() === $callerStack);
DDTrace\remove_hook($id);

var_dump(span_cleanup_target());
DDTrace\switch_stack($parentStack);
DDTrace\close_span();
DDTrace\switch_stack($callerStack);
DDTrace\close_span();
echo "Done.\n";
?>
--EXPECT--
dropped span: span cleanup escaped
bool(true)
bool(true)
dummy span: span cleanup escaped
bool(true)
bool(true)
int(1)
Done.
