--TEST--
Check if we can override method from a parent class in a descendant class
--FILE--
<?php
class Test {
}

$no = 1;
dd_trace("Test", "__construct", function () use ($no) {
    $this->__construct();
    echo "FAKE_CONSTRUCTOR " . $no . PHP_EOL;
    return $this;
});

$a = new Test();

?>
--EXPECT--
FAKE_CONSTRUCTOR 1
