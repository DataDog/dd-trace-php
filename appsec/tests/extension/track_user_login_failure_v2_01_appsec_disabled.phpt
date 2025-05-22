--TEST--
Test \DDTrace\ATO\V2\track_user_login_failure with appsec disabled
--INI--
extension=ddtrace.so
datadog.appsec.testing=0
--ENV--
DD_APPSEC_ENABLED=0
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\DDTrace\ATO\V2\track_user_login_failure(
  "login",
  true,
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
  string(%d) %s
  ["appsec.events.users.login.failure.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.failure.usr.exists"]=>
  string(%d) "true"
  ["appsec.events.users.login.failure.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.failure.sdk"]=>
  string(%d) "true"
  ["appsec.events.users.login.failure.metakey1"]=>
  string(%d) "metavalue"
  ["appsec.events.users.login.failure.metakey2"]=>
  string(%d) "metavalue02"
}