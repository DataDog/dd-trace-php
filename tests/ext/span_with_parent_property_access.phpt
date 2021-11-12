--TEST--
Test DDTrace\SpanData::$parent
--FILE--
<?php
$root = DDTrace\start_span();

$span = DDTrace\start_span();

if ($span->parent === $root) {
    echo "OK\n";

    try {
        $span->parent = null;
    } catch (\Error $error) {
        print $error->getMessage() . PHP_EOL;
    } finally {
        if ($span->parent === null) {
            echo "FAIL\n";
        }
    }
} else {
    var_dump($root, $span);
}
?>
--EXPECT--
OK
Cannot modify readonly property DDTrace\SpanData::parent
