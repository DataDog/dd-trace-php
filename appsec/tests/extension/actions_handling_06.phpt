--TEST--
When multiple redirects, the first one is used
--INI--
datadog.appsec.enabled=1
extension=ddtrace.so
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_HTML=tests/extension/templates/response.html
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []], ['redirect', ['status_code' => '301', 'location' => 'http://alex.com']], ['redirect', ['status_code' => '302', 'location' => 'http://other.com']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 301 Moved Permanently
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and attempted a redirection to http://alex.com in %s on line %s