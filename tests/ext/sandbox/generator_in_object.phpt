--TEST--
Test tracing generators within objects
--FILE--
<?php

class A {
    public $val = 1;

    function gen() {
        yield $this->val++;
        return $this->val;
    }
}

DDTrace\trace_method('A', 'gen', function($span, $args, $val) {
    print "Class: " . get_class($this) . "; value $val\n";
});

$g = (new A)->gen();
var_dump($g->current());
$g->next();
var_dump($g->getReturn());

?>
--EXPECT--
Class: A; value 1
int(1)
Class: A; value 2
int(2)
