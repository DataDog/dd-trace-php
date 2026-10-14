--TEST--
DDTrace\curl_multi_exec_get_request_spans() returns by reference and can be used by value
--DESCRIPTION--
PHP 8.6 stores call results used by value in TMP variables, and by-reference internal
functions must unwrap the reference there. Otherwise e.g. count() gets a reference and
throws a TypeError.
--SKIPIF--
<?php if (PHP_VERSION_ID >= 80600 && PHP_DEBUG) die('skip: PHP 8.6 debug builds assert that a by-reference internal call returns a reference, even into a TMP'); ?>
--FILE--
<?php

$spans = &DDTrace\curl_multi_exec_get_request_spans();
$spans[] = 1;

var_dump(count(DDTrace\curl_multi_exec_get_request_spans()));
var_dump(DDTrace\curl_multi_exec_get_request_spans()[0]);
var_dump(DDTrace\curl_multi_exec_get_request_spans() + [1 => 2]);

$copy = DDTrace\curl_multi_exec_get_request_spans();
$copy[] = 3;
var_dump(count($spans));

?>
--EXPECT--
int(1)
int(1)
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
int(1)
