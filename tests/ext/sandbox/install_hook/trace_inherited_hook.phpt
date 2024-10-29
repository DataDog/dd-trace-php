--TEST--
Installing hook on inherited method
--FILE--
<?php

abstract class A {
    public function foo() {
        echo "A::foo\n";
    }
}

class B extends A {
}

\DDTrace\install_hook(
    'B::foo',
    function () {
        echo "B::foo\n";
    }
);

$b = new B();
$b->foo();
?>
--EXPECT--
