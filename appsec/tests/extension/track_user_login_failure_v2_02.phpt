--TEST--
Test \DDTrace\ATO\V2\track_user_login_failure without metadata
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\DDTrace\ATO\V2\track_user_login_failure(
  "login",
  false
);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(5) {
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
}