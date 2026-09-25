--TEST--
Test \datadog\appsec\v2\track_user_login_success id is not mandatory on user object
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
var_dump($root->attributes);

?>
--EXPECTF--
array(8) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
  ["appsec.events.users.login.success.usr.some_key"]=>
  string(10) "some value"
  ["appsec.events.users.login.success.usr.login"]=>
  string(5) "login"
  ["appsec.events.users.login.success.track"]=>
  string(4) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(4) "true"
  ["appsec.events.users.login.success.metakey1"]=>
  string(9) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(11) "metavalue02"
}
