--TEST--
request_shutdown relays telemetry metrics from the daemon
--INI--
datadog.appsec.enabled=1
display_errors=1
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], [], false, [],
    [], [], ["waf.requests" => [[2.0, "foo=bar"], [1.0, "a=b"]]]]))
]);

var_dump(rinit());
$helper->get_commands(); // ignore

var_dump(rshutdown());
$helper->get_commands();

?>
--EXPECTF--
bool(true)

Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=2.000000 tags=foo=bar in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=1.000000 tags=a=b in %s on line %d
bool(true)
