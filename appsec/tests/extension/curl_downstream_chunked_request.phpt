--TEST--
Downstream curl requests: chunked uploads with 100 Continue
--DESCRIPTION--
An upload whose size libcurl doesn't know (CURLOPT_UPLOAD/CURLOPT_PUT with a
READFUNCTION and no INFILESIZE, or a POST with an explicit
"Transfer-Encoding: chunked") is sent chunked, and on HTTP/1.1 with
"Expect: 100-continue". The request as sent is not observed (G5.5): the
server echoes what it got. The 100 response head is discarded at its blank
line (counted as interim) and the final head and its body are collected
(G5.11).
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

function reader(array $pieces) {
    return function ($ch, $fd, $len) use (&$pieces) {
        return (string)array_shift($pieces);
    };
}

$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_READFUNCTION, reader(['hello ', 'chunked ', 'world']));
var_dump(curl_exec($ch));
cd_dump($ch, 'PUT, unknown size');

$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Transfer-Encoding: chunked', 'Content-Type: text/plain']);
curl_setopt($ch, CURLOPT_READFUNCTION, reader([str_repeat('a', 3000), str_repeat('b', 10)]));
$res = curl_exec($ch);
var_dump(strlen($res), md5($res) === md5(str_repeat('a', 3000) . str_repeat('b', 10)));
cd_dump($ch, 'POST, explicit chunked');
?>
--EXPECT--
string(19) "hello chunked world"
== PUT, unknown size
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=1 head_complete=yes
  resp headers: [connection=close, content-length=19, content-type=text/plain]
  resp body: 'hello chunked world' complete=yes truncated=no
int(3010)
bool(true)
== POST, explicit chunked
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=1 head_complete=yes
  resp headers: [connection=close, content-length=3010, content-type=text/plain]
  resp body: 3010 bytes md5=969beb733a52349cc5da325d11abb3cc complete=yes truncated=no
