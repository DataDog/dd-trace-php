--TEST--
Test \DDTrace\set_user with propagation enabled by default through configuration
--ENV--
DD_TRACE_PROPAGATE_USER_ID_DEFAULT=true
--FILE--
<?php
DDTrace\set_user("admin", ["policy" => "none", "permissions" => "777"]);
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
