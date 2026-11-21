--TEST--
DDTrace\curl_multi_exec_get_request_spans() returns by reference and can be used by value
--DESCRIPTION--
PHP 8.6 stores call results used by value in TMP variables, and by-reference internal
functions must unwrap the reference there. Otherwise e.g. count() gets a reference and
throws a TypeError. The same holds for a value set by overrideReturnValue().
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

DDTrace\install_hook('DDTrace\curl_multi_exec_get_request_spans', null, function (DDTrace\HookData $hook) {
    $hook->overrideReturnValue([1, 2]);
});
var_dump(count(DDTrace\curl_multi_exec_get_request_spans()));

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
int(2)
