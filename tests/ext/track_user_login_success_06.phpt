--TEST--
Test \DDTrace\ATO\V2\track_user_login_success no user id given
--FILE--
<?php
\DDTrace\ATO\V2\track_user_login_success("login", NULL, [
  'metakey1' => 'meta value'
]);
$root = \DDTrace\root_span();
var_dump($root->meta);

?>
--EXPECTF--
array(5) {
  ["runtime-id"]=>
  string(%d) %s
  ["appsec.events.users.login.success.usr.login"]=>
  string(%d) "login"
  ["appsec.events.users.login.success.track"]=>
  string(%d) "true"
  ["_dd.appsec.events.users.login.success.sdk"]=>
  string(%d) "true"
  ["appsec.events.users.login.success.metakey1"]=>
  string(%d) "meta value"
}