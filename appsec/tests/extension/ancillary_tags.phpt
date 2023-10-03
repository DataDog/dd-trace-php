--TEST--
Test full set of ancillary tags
--INI--
datadog.appsec.extra_headers=,mY-header,,my-other-header
--ENV--
REQUEST_URI=/my/ur%69/
SCRIPT_NAME=/my/uri.php
PATH_INFO=/ur%69/
REQUEST_METHOD=GET
URL_SCHEME=http
HTTP_X_FORWARDED_FOR=7.7.7.6,10.0.0.1
HTTP_X_CLIENT_IP=7.7.7.7
HTTP_X_REAL_IP=7.7.7.8
HTTP_X_FORWARDED=for="foo"
HTTP_X_CLUSTER_CLIENT_IP=7.7.7.9
HTTP_FORWARDED_FOR=7.7.7.10,10.0.0.1
HTTP_FORWARDED=for="foo"
HTTP_VIA=HTTP/1.1 GWA
HTTP_TRUE_CLIENT_IP=7.7.7.11
HTTP_CONTENT_TYPE=text/plain
HTTP_CONTENT_LENGTH=0
HTTP_CONTENT_ENCODING=utf-8
HTTP_CONTENT_LANGUATE=pt-PT
HTTP_HOST=myhost:8888
HTTP_USER_AGENT=my user agent
HTTP_ACCEPT=*/*
HTTP_ACCEPT_ENCODING=gzip
HTTP_ACCEPT_LANGUAGE=pt-PT
HTTP_MY_HEADER=my header value
HTTP_MY_OTHER_HEADER=my other header value
HTTP_IGNORED_HEADER=ignored header
REMOTE_ADDR=7.7.7.12
HTTPS=on
--GET--
key=val
--FILE--
<?php

use function datadog\appsec\testing\add_all_ancillary_tags;
use function datadog\appsec\testing\add_basic_ancillary_tags;

header('Content-type: application/json');
header('Content-encoding: foobar');
header('Content-language: pt_PT');
header('Content-length: 42');
header('Content-ignored: 42');
header('Another-header: 42');
http_response_code(404);
flush();

$_SERVER = array();

$all = array();
add_all_ancillary_tags($all);
ksort($all);
print_r($all);

$basic = array();
add_basic_ancillary_tags($basic);
ksort($basic);
print_r($basic);


?>
--EXPECTF--
Array
(
    [http.client_ip] => 7.7.7.6
    [http.method] => GET
    [http.request.headers.accept] => */*
    [http.request.headers.accept-encoding] => gzip
    [http.request.headers.accept-language] => pt-PT
    [http.request.headers.content-encoding] => utf-8
    [http.request.headers.content-length] => 0
    [http.request.headers.content-type] => text/plain
    [http.request.headers.forwarded] => for="foo"
    [http.request.headers.forwarded-for] => 7.7.7.10,10.0.0.1
    [http.request.headers.host] => myhost:8888
    [http.request.headers.my-header] => my header value
    [http.request.headers.my-other-header] => my other header value
    [http.request.headers.true-client-ip] => 7.7.7.11
    [http.request.headers.user-agent] => my user agent
    [http.request.headers.via] => HTTP/1.1 GWA
    [http.request.headers.x-client-ip] => 7.7.7.7
    [http.request.headers.x-cluster-client-ip] => 7.7.7.9
    [http.request.headers.x-forwarded] => for="foo"
    [http.request.headers.x-forwarded-for] => 7.7.7.6,10.0.0.1
    [http.request.headers.x-real-ip] => 7.7.7.8
    [http.response.headers.content-encoding] => foobar
    [http.response.headers.content-language] => pt_PT
    [http.response.headers.content-length] => 42
    [http.response.headers.content-type] => application/json
    [http.status_code] => 404
    [http.url] => https://myhost:8888/my/uri.php
    [http.useragent] => my user agent
    [network.client.ip] => 7.7.7.12
)
Array
(
    [http.client_ip] => 7.7.7.6
    [http.request.headers.forwarded] => for="foo"
    [http.request.headers.forwarded-for] => 7.7.7.10,10.0.0.1
    [http.request.headers.true-client-ip] => 7.7.7.11
    [http.request.headers.via] => HTTP/1.1 GWA
    [http.request.headers.x-cluster-client-ip] => 7.7.7.9
    [http.request.headers.x-forwarded] => for="foo"
    [http.request.headers.x-forwarded-for] => 7.7.7.6,10.0.0.1
    [http.request.headers.x-real-ip] => 7.7.7.8
)
