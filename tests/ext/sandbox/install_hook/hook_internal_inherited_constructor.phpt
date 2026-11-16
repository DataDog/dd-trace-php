--TEST--
Test hooking inherited internal constructors via install_hook()
--FILE--
<?php

\DDTrace\install_hook("X::__construct", function() { echo "hook 1\n"; });

if (true) {
    class X extends ArrayObject {}
}

\DDTrace\install_hook("X::__construct", function() { echo "hook 2\n"; });

$a = (new X);
$a->__construct();
new ArrayObject; // must not trigger the hook

?>
--EXPECT--
hook 1
hook 2
hook 1
hook 2
