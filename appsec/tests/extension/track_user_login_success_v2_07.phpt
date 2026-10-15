--TEST--
Test \datadog\appsec\v2\track_user_login_success no user id or metadata given
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\datadog\appsec\v2\track_user_login_success("login");
$root = \DDTrace\root_span();
$attributes = $root->attributes;
ksort($attributes);
var_dump($attributes);

?>
--EXPECTF--
array(7) {
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
  ["_dd.p.dm"]=>
  string(2) "-5"
  ["_dd.p.ts"]=>
  string(2) "02"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["process_id"]=>
  int(%d)
  ["runtime-id"]=>
  string(%d) %s
}