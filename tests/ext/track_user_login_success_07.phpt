--TEST--
Test \datadog\appsec\v2\track_user_login_success no user id or metadata given
--FILE--
<?php
\datadog\appsec\v2\track_user_login_success("login");
$root = \DDTrace\root_span();
var_dump($root->attributes);

?>
--EXPECTF--
array(5) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
}
