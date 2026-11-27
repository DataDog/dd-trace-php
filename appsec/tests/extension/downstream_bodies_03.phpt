--TEST--
should_send_downstream_bodies samples deterministically with the default configuration
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics,should_send_downstream_bodies,downstream_sampler_set_counter};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
downstream_sampler_set_counter(0); // the counter starts at random
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
