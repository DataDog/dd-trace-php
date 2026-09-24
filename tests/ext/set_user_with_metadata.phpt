--TEST--
Test \DDTrace\set_user with metadata
--FILE--
<?php
DDTrace\set_user("admin", ["policy" => "none", "permissions" => "777"]);
$root = \DDTrace\root_span();
var_dump($root->attributes);

?>
--EXPECTF--
array(5) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
  ["usr.id"]=>
  string(5) "admin"
  ["usr.policy"]=>
  string(4) "none"
  ["usr.permissions"]=>
  string(3) "777"
}
