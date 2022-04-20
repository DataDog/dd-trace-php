--TEST--
DDTrace\hook_function posthook is passed the correct args (variadic)
--FILE--
<?php

var_dump(DDTrace\hook_function('greet',
    null,
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
Hello, Datadog.
array(3) {
  [0]=>
  array(1) {
    [0]=>
    string(7) "Datadog"
  }
  [1]=>
  NULL
  [2]=>
  NULL
}

