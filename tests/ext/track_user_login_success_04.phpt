--TEST--
Test \datadog\appsec\v2\track_user_login_success id should be present on user object when given user object
--FILE--
<?php
\datadog\appsec\v2\track_user_login_success(
  "login",
  [
    "some_key" => "some value",
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