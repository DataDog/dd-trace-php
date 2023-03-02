--TEST--
Do not throw exceptions when veryfying if class/method and function exists.
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php

class ExampleClass
{
    function this_method_exists(){

    }
}

function this_function_exists(){

}

function format_bool($rv) {
    return ($rv ? "TRUE" : "FALSE" ) . PHP_EOL;
}

error_reporting(E_ALL & ~E_DEPRECATED);
echo format_bool(dd_trace("ThisClassDoesntExists", "m", function(){}));
echo format_bool(dd_trace("ExampleClass", "this_method_exists", function(){}));
echo format_bool(dd_trace("ExampleClass", "method_doesnt_exist", function(){}));

echo format_bool(dd_trace("this_function_doesnt_exist", function(){}));
echo format_bool(dd_trace("this_function_exists", function(){}));
error_reporting(E_ALL);

echo  "no exception thrown" . PHP_EOL;

// dd_trace always returns false now
?>
--EXPECT--
FALSE
FALSE
FALSE
FALSE
FALSE
no exception thrown
