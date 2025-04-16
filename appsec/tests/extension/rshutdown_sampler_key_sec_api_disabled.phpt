--TEST--
request_shutdown — sec api is disabled
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--GET--
key=val
--ENV--
DD_API_SECURITY_ENABLED=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function DDTrace\root_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], [], []]))
]);

http_response_code(410);

var_dump(rinit());
$helper->get_commands(); // ignore

$rootSpan = root_span();
$rootSpan->meta["http.method"] = "GET";
$rootSpan->meta["http.route"] = "/foo/bar";


var_dump(rshutdown());
$c = $helper->get_commands();
echo "Sampler hash sent: ", $c[0][1][1], "\n";

?>
--EXPECT--
bool(true)
bool(true)
Sampler hash sent: 0
