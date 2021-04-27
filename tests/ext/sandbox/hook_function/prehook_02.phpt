--TEST--
DDTrace\hook_function prehook is passed the correct args (variadic)
--FILE--
<?php

var_dump(DDTrace\hook_function('greet',
    function () {
        var_dump(func_get_args());
    }
));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
bool(true)
array(1) {
  [0]=>
  array(1) {
    [0]=>
    string(7) "Datadog"
  }
}
Hello, Datadog.

