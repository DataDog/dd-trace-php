--TEST--
Test \datadog\appsec\v2\track_user_login_success no user id or metadata given
--FILE--
<?php
\datadog\appsec\v2\track_user_login_success("login");
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(7) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
  ["service.name"]=>
  string(31) "track_user_login_success_07.php"
  ["operation.name"]=>
  string(31) "track_user_login_success_07.php"
  ["span.type"]=>
  string(3) "cli"
}