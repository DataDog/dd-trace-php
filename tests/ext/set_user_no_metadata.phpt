--TEST--
Test \DDTrace\set_user with no metadata
--FILE--
<?php
DDTrace\set_user("admin");
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(2) {
  ["runtime-id"]=>
  string(36) "%s"
  ["usr.id"]=>
  string(5) "admin"
}
