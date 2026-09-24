--TEST--
User tracking skips empty AppSec payloads and preserves nonempty updates
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]]])),
], ['continuous' => true]);

rinit();
$helper->get_commands();

DDTrace\set_user('');
DDTrace\set_user('', ['email' => 'user@example.com']);
datadog\appsec\track_authenticated_user_event('');
datadog\appsec\internal\track_authenticated_user_event_automated('test', '');

DDTrace\set_user('0');
datadog\appsec\v2\track_user_login_success('', '');
datadog\appsec\v2\track_user_login_failure('', false);

foreach ($helper->get_commands() as $command) {
    echo $command[0], ': ', json_encode($command[1][0]), "\n";
}

$helper->finished_with_commands();
?>
--EXPECT--
request_exec: {"usr.id":"0"}
request_exec: {"server.business_logic.users.login.success":null}
request_exec: {"server.business_logic.users.login.failure":null}
