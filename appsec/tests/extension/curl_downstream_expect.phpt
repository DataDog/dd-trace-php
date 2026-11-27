--TEST--
Downstream curl requests: 100 Continue before a large request body
--DESCRIPTION--
A POST body above libcurl's Expect threshold (1 MiB since 7.74.0) is sent
after a "100 Continue". That interim head is discarded at its blank line and
starts no hop (G5.11): the transfer has one hop, whose head is the final
200. The request as sent is not observed (G5.5); the server gets all of the
body.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();
$body = str_repeat('x', 1200000);
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
var_dump(curl_exec($ch));
$d = datadog\appsec\testing\curl_downstream_dump($ch);
var_dump($d['hops'], $d['response']['interim_responses'], $d['response']['status'],
    $d['response']['body']);
?>
--EXPECT--
string(22) "received 1200000 bytes"
int(1)
int(1)
int(200)
string(22) "received 1200000 bytes"
