--TEST--
[Sandbox regression] Traced functions and methods cannot be reset
--DESCRIPTION--
PHP 8 uses the observer API which installs the begin/end handlers after the first call.
The dispatch is then cached in the runtime cache so that we do not have to check
dd_should_trace() for every function call. This model is not conducive to resetting
all of the instrumented calls. dd_trace_reset() was added to aide testing but appears to
have never been used outside of this one test.
@see https://github.com/DataDog/dd-trace-php/pull/222
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Test for PHP 8+'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
class Test {
    public function m(){
        echo "METHOD" . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function(){
    echo "METHOD HOOK" . PHP_EOL;
});

function test(){
    echo "FUNCTION" . PHP_EOL;
}

DDTrace\trace_function("test", function(){
    echo "FUNCTION HOOK" . PHP_EOL;
});

$object = new Test();
$object->m();
test();

echo PHP_EOL;
echo (dd_trace_reset() ? "TRUE": "FALSE") . PHP_EOL;
echo PHP_EOL;

$object->m();
test();

?>
--EXPECT--
METHOD
METHOD HOOK
FUNCTION
FUNCTION HOOK

Cannot reset traced functions on PHP 8+
FALSE

METHOD
METHOD HOOK
FUNCTION
FUNCTION HOOK
