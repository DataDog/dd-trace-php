--TEST--
Test \datadog\appsec\v2\track_user_login_failure without metadata
--FILE--
<?php
\datadog\appsec\v2\track_user_login_failure(
  "login",
  false
);
$root = \DDTrace\root_span();
var_dump($root->attributes);

?>
--EXPECTF--
array(6) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
  ["appsec.events.users.login.failure.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.failure.usr.exists"]=>
  string(%d) "false"
  ["appsec.events.users.login.failure.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.failure.sdk"]=>
  string(%d) "true"
}
