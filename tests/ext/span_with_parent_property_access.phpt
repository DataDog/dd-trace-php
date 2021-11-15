--TEST--
Test DDTrace\SpanData::$parent
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
$root = DDTrace\start_span();

$span = DDTrace\start_span();

if (!isset($root->parent) && $span->parent === $root) {
    echo "OK\n";

    try {
        $span->parent = null;
    } catch (\Exception $error) {
        print $error->getMessage() . PHP_EOL;
    } catch (\Throwable $error) {
        print $error->getMessage() . PHP_EOL;
    }
} else {
    var_dump($root, $span);
}
?>
--EXPECT--
OK
Cannot modify readonly property DDTrace\SpanData::$parent
