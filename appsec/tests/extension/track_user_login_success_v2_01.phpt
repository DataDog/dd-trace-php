--TEST--
Test \datadog\appsec\v2\track_user_login_success when user is string
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\datadog\appsec\v2\track_user_login_success(
  "login",
  "user_id",
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
  string(%d) "true"
  ["_dd.appsec.user.collection_mode"]=>
  string(%d) "sdk"
  ["_dd.p.dm"]=>
  string(2) "-5"
  ["_dd.p.ts"]=>
  string(2) "02"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%d) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(%d) "metavalue02"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["appsec.events.users.login.success.usr.id"]=>
  string(7) "user_id"
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["process_id"]=>
  int(%d)
  ["runtime-id"]=>
  string(%d) %s
  ["usr.id"]=>
  string(%d) "user_id"
  ["usr.metakey1"]=>
  string(%d) "metavalue"
  ["usr.metakey2"]=>
  string(%d) "metavalue02"
}