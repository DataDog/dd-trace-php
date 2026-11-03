--TEST--
An end-only function hook installed by a begin-only line callback joins the current call
--DESCRIPTION--
The target has no frame record before the line callback installs the function hook.
Joining must preserve the sandbox's saved observer chain and release the hook's frame reference.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip WeakReference requires PHP 7.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Capture {}

function target() {
    $a = 1;
    return $a;
}

$fn = null;
$weak = null;
$line = (new ReflectionFunction('target'))->getStartLine() + 1;
$probe = DDTrace\install_line_hook(__FILE__, $line, function () use (&$fn, &$weak) {
    if ($fn !== null) {
        return;
    }
    $capture = new Capture();
    $weak = WeakReference::create($capture);
    $fn = DDTrace\install_hook('target', null, function () use ($capture) {
        echo "function end\n";
    });
});

echo "call 1\n";
target();
echo "call 2\n";
target();

DDTrace\remove_hook($probe);
DDTrace\remove_hook($fn);
gc_collect_cycles();
echo "capture released: ", var_export($weak->get() === null, true), "\n";
?>
--EXPECT--
call 1
function end
call 2
function end
capture released: true
