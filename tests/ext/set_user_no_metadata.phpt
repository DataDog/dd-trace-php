--TEST--
Test \DDTrace\set_user with no metadata
--FILE--
<?php
DDTrace\set_user("admin");
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(5) {
  ["runtime-id"]=>
  string(36) "%s"
  ["usr.id"]=>
  string(5) "admin"
  ["service.name"]=>
  string(24) "set_user_no_metadata.php"
  ["operation.name"]=>
  string(24) "set_user_no_metadata.php"
  ["span.type"]=>
  string(3) "cli"
}
