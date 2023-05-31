--TEST--
Test \DDTrace\set_user doesn't propagate when overriding configuration
--ENV--
DD_TRACE_PROPAGATE_USER_ID_DEFAULT=true
--FILE--
<?php
DDTrace\set_user("admin", ["policy" => "none", "permissions" => "777"], false);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(4) {
  ["runtime-id"]=>
  string(36) "%s"
  ["usr.id"]=>
  string(5) "admin"
  ["usr.policy"]=>
  string(4) "none"
  ["usr.permissions"]=>
  string(3) "777"
}
