--TEST--
Test \datadog\appsec\v2\track_user_login_failure invalid login
--FILE--
<?php
$emptyLogin = "";
\datadog\appsec\v2\track_user_login_failure(
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