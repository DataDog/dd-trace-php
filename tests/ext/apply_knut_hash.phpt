--TEST--
Returs value from both original and overriding methods
--FILE--
<?php

$operand = (int)13796632237066639397;
$knuthFactor = 1111111111111111111;

//$hashed = dd_knuth($operand);
$hashed = dd_knuth($operand, $knuthFactor, PHP_INT_MAX);
echo $hashed . PHP_EOL;

?>
--EXPECT--
3011279863549647299
