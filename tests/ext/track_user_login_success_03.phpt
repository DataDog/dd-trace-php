--TEST--
Test \datadog\appsec\v2\track_user_login_success login parameter takes precedence over login value on user object
--FILE--
<?php
\datadog\appsec\v2\track_user_login_success(
  "login",
  [
    "id" => "1234",
    "login" => "this should not be used",
  ],
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(11) {
  ["runtime-id"]=>
  string(%s) %s
  ["appsec.events.users.login.success.usr.id"]=>
  string(%s) "1234"
  ["appsec.events.users.login.success.usr.login"]=>
  string(%s) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%s) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%s) "true"
  ["_dd.appsec.user.collection_mode"]=>
  string(%s) "sdk"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%s) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(%s) "metavalue02"
  ["usr.id"]=>
  string(%s) "1234"
  ["usr.metakey1"]=>
  string(%s) "metavalue"
  ["usr.metakey2"]=>
  string(%s) "metavalue02"
}