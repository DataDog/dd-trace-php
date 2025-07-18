--TEST--
Test \datadog\appsec\v2\track_user_login_failure
--FILE--
<?php
\datadog\appsec\v2\track_user_login_failure(
  "login",
  true,
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(7) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.failure.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.failure.usr.exists"]=>
  string(%d) "true"
  ["appsec.events.users.login.failure.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.failure.sdk"]=>
  string(%d) "true"
  ["appsec.events.users.login.failure.metakey1"]=>
  string(%d) "metavalue"
  ["appsec.events.users.login.failure.metakey2"]=>
  string(%d) "metavalue02"
}