--TEST--
ddtrace generates http.client_ip
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
HTTP_X_FORWARDED_FOR=7.7.7.7
DD_TRACE_CLIENT_IP_HEADER_DISABLED=false
--GET--
key=val
--FILE--
<?php
use function datadog\appsec\testing\{rinit,ddtrace_rshutdown,rshutdown,mlog};
use const datadog\appsec\testing\log_level\DEBUG;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(
        response_request_init(['record', ['{"found":"attack"}','{"another":"attack"}']])
    ),
    response_list(
        response_request_shutdown(['record', ['{"yet another":"attack"}'], ["rshutdown_tag" => "rshutdown_value"], ["rshutdown_metric" => 2.1]])
    ),
], ['continuous' => true]);


rinit();
$helper->get_commands(); //ignore

rshutdown();
$helper->get_commands(); //ignore

ddtrace_rshutdown();
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];

var_dump($tags['http.client_ip']);

$helper->finished_with_commands();
?>
--EXPECTF--
string(7) "7.7.7.7"
