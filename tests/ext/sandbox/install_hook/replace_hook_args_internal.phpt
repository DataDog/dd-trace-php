--TEST--
Adding additional function arguments on internal functions via install_hook()
--FILE--
<?php

$hook = DDTrace\install_hook("preg_replace_callback_array", function($hook) {
    $args = $hook->args;
    $args[] = 2;
    $args[] = null;
    $hook->overrideArguments($args);
});

var_dump(preg_replace_callback_array(["((a))" => function () { var_dump(func_get_args()); }], "ababab"));

?>
--EXPECT--
array(1) {
  [0]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    string(1) "a"
  }
}
array(1) {
  [0]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    string(1) "a"
  }
}
string(4) "bbab"
