--TEST--
Test \datadog\appsec\v2\track_user_login_success login parameter takes precedence over login value on user object
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
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
$attributes = $root->attributes;
ksort($attributes);
var_dump($attributes);

?>
--EXPECTF--
array(14) {
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%s) "true"
  ["_dd.appsec.user.collection_mode"]=>
  string(%s) "sdk"
  ["_dd.p.dm"]=>
  string(2) "-5"
  ["_dd.p.ts"]=>
  string(2) "02"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%s) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(%s) "metavalue02"
  ["appsec.events.users.login.success.track"]=>
  string(%s) "true"
  ["appsec.events.users.login.success.usr.id"]=>
  string(%s) "1234"
  ["appsec.events.users.login.success.usr.login"]=>
  string(%s) "login"
  ["process_id"]=>
  int(%d)
  ["runtime-id"]=>
  string(%s) %s
  ["usr.id"]=>
  string(%s) "1234"
  ["usr.metakey1"]=>
  string(%s) "metavalue"
  ["usr.metakey2"]=>
  string(%s) "metavalue02"
}