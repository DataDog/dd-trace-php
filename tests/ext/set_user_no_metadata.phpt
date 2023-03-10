--TEST--
Test \DDTrace\set_user with no metadata
--FILE--
<?php
DDTrace\set_user("admin");
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(1) {
  ["usr.id"]=>
  string(5) "admin"
}
