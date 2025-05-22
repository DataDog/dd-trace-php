--TEST--
Test \DDTrace\ATO\V2\track_user_login_failure invalid login with appsec disabled
--INI--
extension=ddtrace.so
datadog.appsec.testing=0
--ENV--
DD_APPSEC_ENABLED=0
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
$emptyLogin = "";
\DDTrace\ATO\V2\track_user_login_failure(
  $emptyLogin,
  false
);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(1) {
  ["runtime-id"]=>
  string(%d) %s
}