--TEST--
Test \datadog\appsec\v2\track_user_login_success id should be string to be set on meta
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
    "id" => 1234,
  ],
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
  string(%s) %s
  ["appsec.events.users.login.success.usr.login"]=>
  string(5) "login"
  ["appsec.events.users.login.success.track"]=>
  string(4) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(4) "true"
  ["appsec.events.users.login.success.metakey1"]=>
  string(9) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(11) "metavalue02"
  ["_dd.p.ts"]=>
  string(2) "02"
}