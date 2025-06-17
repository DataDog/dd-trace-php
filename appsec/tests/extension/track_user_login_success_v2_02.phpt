--TEST--
Test \datadog\appsec\v2\track_user_login_success when user is an array
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
    "email" => "mail@to.es",
    "name" => "user name"
  ],
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(14) {
  ["runtime-id"]=>
  string(%s) %s
  ["appsec.events.users.login.success.usr.id"]=>
  string(%s) "1234"
  ["appsec.events.users.login.success.usr.email"]=>
  string(%s) "mail@to.es"
  ["appsec.events.users.login.success.usr.name"]=>
  string(%s) "user name"
  ["appsec.events.users.login.success.usr.login"]=>
  string(%s) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%s) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%s) "true"
  ["_dd.appsec.user.collection_mode"]=>
  string(%s) "sdk"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%s) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(%s) "metavalue02"
  ["usr.id"]=>
  string(%s) "1234"
  ["usr.metakey1"]=>
  string(%s) "metavalue"
  ["usr.metakey2"]=>
  string(%s) "metavalue02"
  ["_dd.p.ts"]=>
  string(2) "02"
}