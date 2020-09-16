--TEST--
[Sandbox regression] Trace class constructor
--FILE--
<?php
class Test {
    public function __construct() {
        echo "METHOD" . PHP_EOL;
    }
}

$no = 1;
DDTrace\trace_method("Test", "__construct", function () use ($no) {
    echo "HOOK " . $no . PHP_EOL;
});

$a = new Test();

?>
--EXPECT--
METHOD
HOOK 1
