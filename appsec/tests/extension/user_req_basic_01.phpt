--TEST--
User requests: basic functionality
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\active_stack;
use function DDtrace\close_span;
use function DDTrace\create_stack;
use function DDTrace\start_span;
use function DDTrace\switch_stack;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createinitedRun([
    response_list(response_request_init([[['block', new ArrayObject()]], ['{"yet another":"attack"}'], true])),
    response_list(response_request_shutdown([[['block', new ArrayObject()]], ['{"yet another":"attack"}'], true]))
]);

$stack = create_stack();
$span = start_span();
switch_stack();

$res = notify_start($span, array(
    '_GET' => ['k1' => ['v1', 'v2'], 'k2' => 'v3 x'],
    '_POST' => ['k3' => ['v4', 'v5'], 'k4' => 'v6'],
    '_SERVER' => [
        'REMOTE_ADDR' => '1.2.3.4',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/foo?k1[]=v1&k1[]=v2&k2=>v3%20x',
        'HTTPS' => 'off',
        'SERVER_NAME' => 'example.com',
        'SERVER_PORT' => 80,
        'HTTP_HOST' => 'example2.com',
        'HTTP_CLIENT_IP' => '2.3.4.5',
        'COOKIE' => 'a=b',
    ],
    '_COOKIE' => ['a' => 'b'],
    '_FILES' => [
        'myfile' => [
            'name' => 'myfile.txt',
            'type' => 'text/plain;charset=utf-8',
            'size' => '123',
            'tmp_name' => '/tmp/fake_name.txt',
        ],
    ]
));
echo "Result of notify_start:\n";
print_r($res);

$res = notify_commit($span, 200, array(
    'Content-type' => ['text/html'],
    'Set-Cookie' => ['a=x', 'b=y'],
));
echo "Result of notify_commit:\n";
print_r($res);


switch_stack($stack);

close_span(100.0);

$c = $helper->get_commands();
print_r($c[1]);
print_r($c[2]);
--EXPECTF--
Result of notify_start:
Array
(
    [status] => 403
    [body] => {"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}], "security_response_id": ""}
    [headers] => Array
        (
            [Content-Type] => application/json
            [Content-Length] => 195
        )

)
Result of notify_commit:
Array
(
    [status] => 403
    [body] => {"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}], "security_response_id": ""}
    [headers] => Array
        (
            [Content-Type] => application/json
            [Content-Length] => 195
        )

)
Array
(
    [0] => request_init
    [1] => Array
        (
            [0] => Array
                (
                    [server.request.query] => Array
                        (
                            [k1] => Array
                                (
                                    [0] => v1
                                    [1] => v2
                                )

                            [k2] => v3 x
                        )

                    [server.request.method] => GET
                    [server.request.cookies] => Array
                        (
                            [a] => b
                        )

                    [server.request.uri.raw] => /foo?k1[]=v1&k1[]=v2&k2=>v3%20x
                    [server.request.headers.no_cookies] => Array
                        (
                            [host] => example2.com
                            [client-ip] => 2.3.4.5
                        )

                    [server.request.body] => Array
                        (
                            [k3] => Array
                                (
                                    [0] => v4
                                    [1] => v5
                                )

                            [k4] => v6
                        )

                    [server.request.body.filenames] => Array
                        (
                            [0] => myfile.txt
                        )

                    [server.request.body.files_field_names] => Array
                        (
                            [0] => myfile
                        )

                    [server.request.path_params] => Array
                        (
                            [0] => foo
                        )

                    [http.client_ip] => 1.2.3.4
                )

        )

)
Array
(
    [0] => request_shutdown
    [1] => Array
        (
            [0] => Array
                (
                    [server.response.status] => 200
                    [server.response.headers.no_cookies] => Array
                        (
                            [Content-type] => Array
                                (
                                    [0] => text/html
                                )

                            [Set-Cookie] => Array
                                (
                                    [0] => a=x
                                    [1] => b=y
                                )

                        )

                )

            [1] => 0
            [2] => %s
        )

)
