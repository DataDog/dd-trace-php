--TEST--
request_shutdown — DD_APPSEC_MAX_BODY_BUFF_SIZE is capped when DD_APPSEC_RAW_RESPONSE_BODY_ENABLED is set
--INI--
expose_php=0
datadog.appsec.enabled=1
datadog.appsec.raw_response_body_enabled=1
datadog.appsec.max_body_buff_size=5242880
datadog.appsec.log_level=warning
datadog.appsec.log_file=/tmp/php_appsec_test.log
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

header('content-type: text/plain');
http_response_code(200);
var_dump(rinit());
echo "body\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
$data = $c[0][1][0];

var_dump(isset($data['server.response.body.raw']));
echo $data['server.response.body.raw'];

match_log('/DD_APPSEC_MAX_BODY_BUFF_SIZE .* exceeds maximum allowed/');
?>
--EXPECT--
bool(true)
body
bool(true)
bool(true)
body
found message in log matching /DD_APPSEC_MAX_BODY_BUFF_SIZE .* exceeds maximum allowed/
