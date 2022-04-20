--TEST--
DDTrace\hook_method prehook is passed the correct args (variadic)
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function () {
        var_dump(func_get_args());
    }
));

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

Greeter::greet('Datadog');

?>
--EXPECT--
bool(true)
array(5) {
  [0]=>
  NULL
  [1]=>
  string(7) "Greeter"
  [2]=>
  array(1) {
    [0]=>
    string(7) "Datadog"
  }
  [3]=>
  NULL
  [4]=>
  NULL
}
Hello, Datadog.

