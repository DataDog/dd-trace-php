--TEST--
Calling DDTrace\trace_method() overwrites previous tracing functions in unresolved aliases
--FILE--
<?php

class A {
    function func() {
        print "Func\n";
    }
}

DDTrace\trace_method("A", "func", function () { print "A-trace\n"; });
DDTrace\trace_method("B", "func", function () { print "B-trace\n"; });

(new A)->func();

if (true) {
    class B extends A {}
}

(new A)->func();
(new B)->func();

?>
--EXPECT--
Func
A-trace
Func
A-trace
Func
A-trace
B-trace