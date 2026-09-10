--TEST--
appsec.api_security.request.schema is reported when the helper returns schemas
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test_apisec_schema.log
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
        ['_dd.appsec.s.req.headers' => 'W3siYSI6WzhdfV0='],
        [],
    ]))
]);

http_response_code(200);

var_dump(rinit());
$helper->get_commands(); // ignore

$rootSpan = root_span();
$rootSpan->meta["http.method"] = "GET";
$rootSpan->meta["http.route"] = "/foo/bar";
// framework names are normalized: lowercased, spaces replaced with underscores
$rootSpan->meta["component"] = "Laravel Framework";

var_dump(rshutdown());
$helper->get_commands(); // ignore

match_log('/Telemetry metric api_security\.request\.schema added with tags framework:laravel_framework and value 1/');
no_match_log('/api_security\.request\.no_schema/', '/api_security\.missing_route/');

?>
--EXPECT--
bool(true)
bool(true)
found message in log matching /Telemetry metric api_security\.request\.schema added with tags framework:laravel_framework and value 1/
no message in log matching /api_security\.request\.no_schema/, /api_security\.missing_route/
