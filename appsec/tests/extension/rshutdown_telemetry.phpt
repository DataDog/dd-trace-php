--TEST--
request_shutdown relays telemetry metrics from the daemon
--INI--
expose_php=0
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

\datadog\appsec\testing\stop_for_debugger();
$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []])),
    response_list(response_request_shutdown(['ok', [], new ArrayObject(), false, [],
    [], ["waf.requests" => [[2.0, "foo=bar"], [1.0, "a=b"]]]]))
]);

var_dump(rinit());
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0]);

?>
--EXPECT--
bool(true)
bool(true)
Array
(
    [0] => request_shutdown
    [1] => Array
        (
            [0] => Array
                (
                    [server.response.status] => 403
                    [server.response.headers.no_cookies] => Array
                        (
                            [foo] => Array
                                (
                                    [0] => bar
                                )

                            [my-header] => Array
                                (
                                    [0] => value 1
                                    [1] => value 2
                                )

                            [content-type] => Array
                                (
                                    [0] => text/plain;charset=ISO-8859-1
                                )

                        )

                )

        )

)
