--TEST--
Check if we can override mysqli constructor
--FILE--
<?php

$no = 1;
dd_trace("mysqli", "__construct", function (...$args) use ($no) {
    $this->__construct(...$args);
    echo "MYSQLI CONSTRUCTOR " . $no . PHP_EOL;
    return $this;
});

new mysqli();

?>
--EXPECT--
MYSQLI CONSTRUCTOR 1
