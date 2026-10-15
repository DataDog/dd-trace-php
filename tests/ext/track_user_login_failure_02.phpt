--TEST--
Test \datadog\appsec\v2\track_user_login_failure without metadata
--FILE--
<?php
\datadog\appsec\v2\track_user_login_failure(
  "login",
  false
);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(8) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.failure.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.failure.usr.exists"]=>
  string(%d) "false"
  ["appsec.events.users.login.failure.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.failure.sdk"]=>
  string(%d) "true"
  ["service.name"]=>
  string(31) "track_user_login_failure_02.php"
  ["operation.name"]=>
  string(31) "track_user_login_failure_02.php"
  ["span.type"]=>
  string(3) "cli"
}