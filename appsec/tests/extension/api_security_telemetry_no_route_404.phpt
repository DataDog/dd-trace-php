--TEST--
No api_security metric is reported for a routeless 404
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test_apisec_404.log
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

http_response_code(404);

var_dump(rinit());
$helper->get_commands(); // ignore

// an endpoint that does not exist has no route to speak of; it must not be
// counted as a missing route
$rootSpan = root_span();
$rootSpan->meta["http.method"] = "GET";

var_dump(rshutdown());
$helper->get_commands(); // ignore

no_match_log('/api_security\./');

?>
--EXPECT--
bool(true)
bool(true)
no message in log matching /api_security\./
