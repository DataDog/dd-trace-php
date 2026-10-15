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
$attributes = $root->attributes;
ksort($attributes);
var_dump($attributes);

?>
--EXPECTF--
array(4) {
  ["_dd.p.dm"]=>
  string(2) "-5"
  ["_dd.p.ts"]=>
  string(2) "02"
  ["process_id"]=>
  int(%d)
  ["runtime-id"]=>
  string(%d) %s
}