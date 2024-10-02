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

echo format_bool(DDTrace\trace_method("ThisClassDoesntExists", "m", function(){}));
echo format_bool(DDTrace\trace_method("ExampleClass", "this_method_exists", function(){}));
echo format_bool(DDTrace\trace_method("ExampleClass", "method_doesnt_exist", function(){}));

echo format_bool(DDTrace\trace_function("this_function_doesnt_exist", function(){}));
echo format_bool(DDTrace\trace_function("this_function_exists", function(){}));

echo  "no exception thrown" . PHP_EOL;

?>
--EXPECT--
TRUE
TRUE
FALSE
TRUE
TRUE
no exception thrown
