--TEST--
Annotations
--FILE--
<?php

#[DDTrace\Traces]
class Test {
    #[DDTrace\Trace]
    public function test() {
        return 1;
    }
}

$test = new Test();
$test->test();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECT--
