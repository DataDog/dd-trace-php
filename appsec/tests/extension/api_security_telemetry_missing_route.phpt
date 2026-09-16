--TEST--
appsec.api_security.missing_route is reported when no route can be determined
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test_apisec_missing_route.log
datadog.appsec.log_level=debug
datadog.appsec.enabled=1
--GET--
key=val
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function DDTrace\root_span;

include __DIR__ . '/inc/mock_helper.php';
require __DIR__ . '/inc/logging.php';

truncate_log();

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([
        [['ok', []]],
        [],
        false,
        [],
        [],
        [],
    ]))
]);

http_response_code(410);

var_dump(rinit());
$helper->get_commands(); // ignore

// no http.route, no http.endpoint and no http.url: no route can be determined
$rootSpan = root_span();
$rootSpan->meta["http.method"] = "GET";

var_dump(rshutdown());
$c = $helper->get_commands();
echo "Sampler hash sent: ", $c[0][1][1], "\n";

match_log('/Telemetry metric api_security\.missing_route added with tags framework:unknown and value 1/');
no_match_log('/api_security\.request\./');

?>
--EXPECT--
bool(true)
bool(true)
Sampler hash sent: 0
found message in log matching /Telemetry metric api_security\.missing_route added with tags framework:unknown and value 1/
no message in log matching /api_security\.request\./
