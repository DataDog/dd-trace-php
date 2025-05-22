--TEST--
Test \DDTrace\ATO\V2\track_user_login_success when user is string
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\DDTrace\ATO\V2\track_user_login_success(
  "login",
  "user_id",
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(11) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
  ["_dd.appsec.user.collection_mode"]=>
  string(%d) "sdk"
  ["appsec.events.users.login.success.usr.id"]=>
  string(7) "user_id"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%d) "metavalue"
  ["appsec.events.users.login.success.metakey2"]=>
  string(%d) "metavalue02"
  ["usr.id"]=>
  string(%d) "user_id"
  ["usr.metakey1"]=>
  string(%d) "metavalue"
  ["usr.metakey2"]=>
  string(%d) "metavalue02"
}