--TEST--
Test \DDTrace\set_user with metadata and with usr.id as a distributed tag
--FILE--
<?php
DDTrace\set_user("admin", ["policy" => "none", "permissions" => "777"], true);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(5) {
  ["runtime-id"]=>
  string(36) "%s"
  ["usr.id"]=>
  string(5) "admin"
  ["_dd.p.usr.id"]=>
  string(8) "YWRtaW4="
  ["usr.policy"]=>
  string(4) "none"
  ["usr.permissions"]=>
  string(3) "777"
}
