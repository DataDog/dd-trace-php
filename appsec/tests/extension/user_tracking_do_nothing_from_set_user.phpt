--TEST--
Don't block or redirect from DDTrace\set_user
--INI--
extension=ddtrace.so
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]]])),
], ['continuous' => true]);

rinit();
$helper->get_commands(); // Ignore

DDTrace\set_user("Admin",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

$c = $helper->get_commands();
echo "usr.id:\n";
var_dump($c[0][1][0]['usr.id']);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

$helper->finished_with_commands();
?>
--EXPECTF--
usr.id:
string(5) "Admin"
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => Admin
    [usr.value] => something
    [usr.metadata] => some other metadata
    [usr.email] => noneofyour@business.com
)
