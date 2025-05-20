--TEST--
Test \DDTrace\ATO\V2\track_user_login_success id should be string
--FILE--
<?php
\DDTrace\ATO\V2\track_user_login_success(
  "login",
  [
    "id" => 1234,
  ],
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(1) {
  ["runtime-id"]=>
  string(%s) %s
}