--TEST--
Basic RINIT/RSHUTDOWN sequence with mock helper
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.waf_timeout=42
datadog.appsec.log_level=debug
datadog.appsec.testing_raw_body=1
datadog.appsec.enabled=1
datadog.trace.agent_port=18126
datadog.extra_services=,some,extra,services,
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REQUEST_URI=/my/ur+%00i%0/?key[a][]=v%61l&key[a][]=val2&foo=bar
URL_SCHEME=http
HTTP_CONTENT_TYPE=text/plain
HTTP_CONTENT_LENGTH=0
DD_APPSEC_OBFUSCATION_PARAMETER_KEY_REGEXP=hello
DD_APPSEC_OBFUSCATION_PARAMETER_VALUE_REGEXP=goodbye
REMOTE_ADDR=1.2.3.4
DD_VERSION=1.1
DD_SERVICE=appsec_tests
--COOKIE--
c[a]=3; d[]=5; d[]=6
--GET--
key[a][]=v%61l&key[a][]=val2&foo=bar
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,get_formatted_runtime_id};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());
var_dump(rshutdown());

$helper->print_commands();

DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["runtime-id"] == get_formatted_runtime_id());
?>
--EXPECTF--
bool(true)
bool(true)
Array
(
    [0] => Array
        (
            [0] => client_init
            [1] => Array
                (
                    [0] => %s
                    [1] => %s
                    [2] => %s
                    [3] => %d
                    [4] => Array
                        (
                            [obfuscator_key_regex] => hello
                            [obfuscator_value_regex] => goodbye
                            [rules_file] => /my/rules_file.json
                            [schema_extraction] => Array
                                (
                                    [enabled] => 1
                                    [sampling_period] => 30
                                )

                            [trace_rate_limit] => 100
                            [waf_timeout_us] => 42
                        )

                    [5] => Array
                        (
                            [enabled] => 1
                            [shmem_path] => 
                        )

                    [6] => Array
                        (
                            [runtime_id] => %s
                            [session_id] => 
                        )

                )

        )

    [1] => Array
        (
            [0] => request_init
            [1] => Array
                (
                    [0] => Array
                        (
                            [http.client_ip] => 1.2.3.4
                            [server.request.body] => Array
                                (
                                )

                            [server.request.body.filenames] => Array
                                (
                                )

                            [server.request.body.files_field_names] => Array
                                (
                                )

                            [server.request.body.raw] => 
                            [server.request.cookies] => Array
                                (
                                    [c] => Array
                                        (
                                            [a] => 3
                                        )

                                    [d] => Array
                                        (
                                            [0] => 5
                                            [1] => 6
                                        )

                                )

                            [server.request.headers.no_cookies] => Array
                                (
                                    [content-length] => 0
                                    [content-type] => text/plain
                                )

                            [server.request.method] => GET
                            [server.request.path_params] => Array
                                (
                                    [0] => my
                                    [1] => ur+ i%0
                                )

                            [server.request.query] => Array
                                (
                                    [foo] => bar
                                    [key] => Array
                                        (
                                            [a] => Array
                                                (
                                                    [0] => val
                                                    [1] => val2
                                                )

                                        )

                                )

                            [server.request.uri.raw] => /my/ur+%00i%0/?key[a][]=v%61l&key[a][]=val2&foo=bar
                        )

                )

        )

)
bool(true)
