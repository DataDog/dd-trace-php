--TEST--
Writing of ancillary tags doesn't replace existing headers
--ENV--
REQUEST_URI=/my/ur%69/
SCRIPT_NAME=/my/uri.php
PATH_INFO=/ur%69/
REQUEST_METHOD=GET
URL_SCHEME=http
HTTP_X_FORWARDED_FOR=7.7.7.7,10.0.0.1
HTTP_USER_AGENT=my user agent
REMOTE_ADDR=1.1.1.1
--GET--
key=val
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

header('Content-type: application/json');
http_response_code(404);
flush();

$_SERVER = array();

$all = array(
    'http.method' => 'POST',
    'http.request.headers.x-forwarded-for' => '8.8.8.8',
    'http.url' => 'http://foo/bar',
    'http.useragent' => 'other user agent',
    'http.status_code' => '405',
    'http.response.headers.content-type' => 'text/xml',
    'network.client.ip' => '2.2.2.2',
    'http.client_ip' => '5.5.5.5'
);
add_all_ancillary_tags($all);
ksort($all);
print_r($all);

add_basic_ancillary_tags($all);
ksort($all);
print_r($all);

?>
--EXPECTF--
Array
(
    [http.client_ip] => 5.5.5.5
    [http.method] => POST
    [http.request.headers.user-agent] => my user agent
    [http.request.headers.x-forwarded-for] => 8.8.8.8
    [http.response.headers.content-type] => text/xml
    [http.status_code] => 405
    [http.url] => http://foo/bar
    [http.useragent] => other user agent
    [network.client.ip] => 2.2.2.2
)
Array
(
    [http.client_ip] => 5.5.5.5
    [http.method] => POST
    [http.request.headers.user-agent] => my user agent
    [http.request.headers.x-forwarded-for] => 8.8.8.8
    [http.response.headers.content-type] => text/xml
    [http.status_code] => 405
    [http.url] => http://foo/bar
    [http.useragent] => other user agent
    [network.client.ip] => 2.2.2.2
)
