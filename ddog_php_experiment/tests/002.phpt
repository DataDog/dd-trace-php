--TEST--
test1() Basic test
--EXTENSIONS--
ddog_php_experiment
--FILE--
<?php
$ret = test1();

var_dump($ret);
?>
--EXPECT--
The extension ddog_php_experiment is loaded and working!
NULL
