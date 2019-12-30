--TEST--
Auto instrumentation
--SKIPIF--
<?php if (!class_exists('DDTrace\\FakeTracingClosure')) die('skip Auto-instrumentation build required'); ?>
--INI--
ddtrace.enable_auto_instrumentation=1
--FILE--
<?php
dd_trace_function('date_default_timezone_set', function (DDTrace\SpanData $span) {
    $span->name = 'date_default_timezone_set';
});

date_default_timezone_set('UTC');

function myFunc($foo) {
    return $foo;
}

class MyClass {
    public function myMethod($foo) {
        return $foo;
    }
}

var_dump(myFunc(42));
$myObj = new MyClass();
var_dump($myObj->myMethod(1337));
var_dump(array_sum([1, 3, 5]));
$dt = new DateTime('2019-12-30');
$dt->setTime(8, 10);
$dt->format('r');

$spans = dd_trace_serialize_closed_spans();
printf('Spans count: %d', count($spans));
echo PHP_EOL;
array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, $spans);
?>
--EXPECT--
int(42)
int(1337)
int(9)
Spans count: 11
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
__dd_auto_instrumented
date_default_timezone_set
__dd_auto_instrumented
