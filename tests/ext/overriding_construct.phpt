--TEST--
Check if we can override method from a parent class in a descendant class
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
class Test {
    public function __construct() {
        echo "METHOD" . PHP_EOL;
    }
}

$no = 1;
dd_trace("Test", "__construct", function () use ($no) {
    $this->__construct();
    echo "HOOK " . $no . PHP_EOL;
    return $this;
});

$a = new Test();

?>
--EXPECT--
METHOD
HOOK 1
