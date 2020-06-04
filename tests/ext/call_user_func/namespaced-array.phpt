--TEST--
Test that call_user_func_array can trace inside a namespace
--SKIPIF--
<?php if (PHP_MAJOR_VERSION != 5) die("skip: only supported on PHP 5 atm"); ?>
--FILE--
<?php

namespace {
    dd_trace_method('DatadogTest\\Class1', 'method1', function () {
        echo "DatadogTest\\Class1::method1 instrumented\n";
    });

    dd_trace_function('DatadogTest\\function1', function () {
        echo "DatadogTest\\function1 instrumented\n";
    });
}
namespace DatadogTest {
    final class Class1
    {
        public static function method1($msg)
        {
            echo "Class1::method1 {$msg}\n";
        }
    }

    function function1($msg)
    {
        echo "function1 {$msg}\n";
    }

    call_user_func_array('DatadogTest\\Class1::method1', ['called']);
    call_user_func_array('DatadogTest\\function1', ['called']);
}

?>
--EXPECT--
Class1::method1 called
DatadogTest\Class1::method1 instrumented
function1 called
DatadogTest\function1 instrumented

