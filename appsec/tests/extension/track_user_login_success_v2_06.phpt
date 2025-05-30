--TEST--
Test \DDTrace\ATO\V2\track_user_login_success no user id given
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\DDTrace\ATO\V2\track_user_login_success("login", NULL, [
  'metakey1' => 'meta value'
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(6) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%d) "meta value"
  ["_dd.p.ts"]=>
  string(2) "02"
}