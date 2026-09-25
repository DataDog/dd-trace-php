--TEST--
Test \DDTrace\set_user with no metadata
--FILE--
<?php
DDTrace\set_user("admin");
$root = \DDTrace\root_span();
var_dump($root->attributes);

?>
--EXPECTF--
array(3) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
  ["usr.id"]=>
  string(5) "admin"
}
