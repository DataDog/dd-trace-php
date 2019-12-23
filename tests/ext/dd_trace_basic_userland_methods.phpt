--TEST--
dd_trace() basic functionality (userland methods)
--FILE--
<?php
class MyClass
{
    public function myMethod1($foo) {
        return $foo;
    }

    public function myMethod2($bar) {
        return $bar;
    }
}

dd_trace('MyClass', 'myMethod1', function () {
    echo 'MyClass.myMethod1' . PHP_EOL;
    return dd_trace_forward_call();
});

dd_trace('MyClass', 'myMethod2', function () {
    echo 'MyClass.myMethod2' . PHP_EOL;
    return dd_trace_forward_call();
});

$mc = new MyClass();
$mc->myMethod2('foo');
for ($i = 0; $i < 10; $i++) {
    $mc->myMethod1('bar');
}
$mc->myMethod2(42);
$mc->myMethod2(true);
?>
--EXPECT--
MyClass.myMethod2
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod1
MyClass.myMethod2
MyClass.myMethod2
