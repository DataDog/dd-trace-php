--TEST--
LineHookData reads a suspended fiber's variables and restores the active frame
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip: fibers require PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function fiber_locals($arg) {
    $local = $arg . '-local';
    return $local;
}

$saved = null;
$line = (new ReflectionFunction('fiber_locals'))->getStartLine() + 2;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($hook) use (&$saved) {
    $saved = $hook;
    Fiber::suspend();
    echo json_encode([$hook->var('arg'), $hook->vars()['local']]), "\n";
});

$fiber = new Fiber(function () { fiber_locals('fiber'); });
$fiber->start();
$arg = $local = 'outside';
echo json_encode([$saved->var('arg'), $saved->vars()['local']]), "\n";
$fiber->resume();
var_dump($saved->vars() === []);
DDTrace\remove_hook($id);
?>
--EXPECT--
["fiber","fiber-local"]
["fiber","fiber-local"]
bool(true)
