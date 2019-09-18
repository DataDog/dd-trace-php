--TEST--
[Sandbox regression] Trace class constructor
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Test {
    public function __construct() {
        echo "METHOD" . PHP_EOL;
    }
}

$no = 1;
dd_trace_method("Test", "__construct", function () use ($no) {
    echo "HOOK " . $no . PHP_EOL;
});

$a = new Test();

?>
--EXPECT--
METHOD
HOOK 1
