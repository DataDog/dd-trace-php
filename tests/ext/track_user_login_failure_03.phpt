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
// An invalid login must write nothing: only the tracer's own tags, in either array.
var_dump($root->attributes);
var_dump($root->meta);

?>
--EXPECTF--
array(2) {
  ["runtime-id"]=>
  string(36) "%s"
  ["process_id"]=>
  float(%f)
}
array(0) {
}
