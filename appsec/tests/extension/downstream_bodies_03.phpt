--TEST--
should_send_downstream_bodies samples deterministically with the default configuration
--INI--
datadog.appsec.enabled=1
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
for ($i = 0; $i < 3; $i++) {
    var_dump(should_send_downstream_bodies());
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
float(1)
