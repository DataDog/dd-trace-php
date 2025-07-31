--TEST--
Test \datadog\appsec\v2\track_user_login_failure invalid login
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
$emptyLogin = "";
\datadog\appsec\v2\track_user_login_failure(
  $emptyLogin,
  false
);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(3) {
  ["runtime-id"]=>
  string(%d) %s
  ["_dd.p.ts"]=>
  string(2) "02"
  ["_dd.p.dm"]=>
  string(2) "-5"
}