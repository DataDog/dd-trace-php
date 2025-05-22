--TEST--
Test \DDTrace\ATO\V2\track_user_login_success id should be present on user object when given user object
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');
\DDTrace\ATO\V2\track_user_login_success(
  "login",
  [
    "some_key" => "some value",
  ],
  [
    "metakey1" => "metavalue",
    "metakey2" => "metavalue02",
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(1) {
  ["runtime-id"]=>
  string(%s) %s
}