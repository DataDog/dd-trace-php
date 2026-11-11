--TEST--
should_send_downstream_bodies never samples with a sample rate of 0
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
use function datadog\appsec\should_send_downstream_bodies;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
for ($i = 0; $i < 2; $i++) {
    var_dump(should_send_downstream_bodies());
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
?>
--EXPECT--
bool(true)
bool(false)
bool(false)
bool(true)
NULL
