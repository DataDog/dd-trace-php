--TEST--
Suppress function call via suppressCall()
--SKIPIF--
<?php
if (!version_compare(PHP_VERSION, '8.5.0', '>='))
    die('skip: temporary disable on PHP 8.5+');
?>
--FILE--
<?php
function foo() {
    return 'function was called';
}

$hook = DDTrace\install_hook("foo", function($hook) {
    $hook->disableJitInlining();
    $hook->suppressCall();
});

echo "foo() suppressed\n";
var_dump(foo());

DDTrace\remove_hook($hook);

echo "foo() not suppressed\n";
var_dump(foo());

function fooStr() : string {
    echo 'fooStr() function was called', "\n";
    return 'function was called';
}

$hook = DDTrace\install_hook("fooStr", function ($hook) {
    $hook->disableJitInlining();
    $hook->suppressCall();
}, function ($hook) {
    $hook->overrideReturnValue('overriden value');
});


echo "fooStr() suppressed\n";
var_dump(fooStr());

DDTrace\remove_hook($hook);

echo "fooStr() not suppressed\n";
var_dump(fooStr());
?>
--EXPECT--
foo() suppressed
NULL
foo() not suppressed
string(19) "function was called"
fooStr() suppressed
string(15) "overriden value"
fooStr() not suppressed
fooStr() function was called
string(19) "function was called"

