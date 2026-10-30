--TEST--
Callbacks suspended in fibers preserve their own observer chains when resumed out of order
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function first() {
    echo "first body\n";
}

function second() {
    echo "second body\n";
}

function main_target() {
    $GLOBALS['ids'][] = DDTrace\install_hook('main_target', null, function () { echo "main end\n"; });
    echo "main body\n";
}

$ids = [];
foreach (['first', 'second'] as $name) {
    $line = (new ReflectionFunction($name))->getStartLine() + 1;
    $ids[] = DDTrace\install_line_hook(__FILE__, $line, function () use ($name, &$ids) {
        echo "$name callback\n";
        Fiber::suspend();
        $ids[] = DDTrace\install_hook($name, null, function () use ($name) { echo "$name end\n"; });
    });
}

$first = new Fiber('first');
$second = new Fiber('second');
$first->start();
$second->start();
main_target();
$first->resume();
$second->resume();

foreach ($ids as $id) DDTrace\remove_hook($id);
?>
--EXPECT--
first callback
second callback
main body
main end
first body
first end
second body
second end
