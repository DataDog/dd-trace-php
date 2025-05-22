--TEST--
Test \DDTrace\ATO\V2\track_user_login_failure invalid login
--INI--
extension=ddtrace.so
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