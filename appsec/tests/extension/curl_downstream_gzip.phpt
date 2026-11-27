--TEST--
Downstream curl requests: content-encoded response bodies are collected as the write function gets them
--DESCRIPTION--
A gzip-encoded response: the collector copies what libcurl's write function
delivers (G7.17). With CURLOPT_ENCODING (ACCEPT_ENCODING) libcurl decodes the
body first, so the copy is the decoded text the app gets; without it the copy
is the compressed bytes, as the app gets them. Status and headers are the
same in both cases. The request as sent (its Accept-Encoding) is not observed
(G5.5).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
if (!(curl_version()['features'] & CURL_VERSION_LIBZ)) die('skip libcurl without zlib');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

$ch = curl_init("$base/gzip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_ENCODING, '');
var_dump(curl_exec($ch));
cd_dump($ch, 'decoded by libcurl');

$ch = curl_init("$base/gzip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
var_dump(bin2hex(substr($res, 0, 2)), strlen($res));
$d = datadog\appsec\testing\curl_downstream_dump($ch);
echo "== raw\n", 'status ', $d['response']['status'], ' ', cd_headers($d['response']['headers']), "\n";
echo 'copy is what the app got: ', var_export($d['response']['body'] === $res, true), "\n";
?>
--EXPECT--
string(17) "hello gzip world
"
== decoded by libcurl
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-encoding=gzip, content-length=37, content-type=text/plain]
  resp body: 'hello gzip world
' complete=yes truncated=no
string(4) "1f8b"
int(37)
== raw
status 200 [connection=close, content-encoding=gzip, content-length=37, content-type=text/plain]
copy is what the app got: true
