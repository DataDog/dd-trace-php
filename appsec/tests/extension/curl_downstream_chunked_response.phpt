--TEST--
Downstream curl requests: chunked responses are collected as libcurl de-chunks them
--DESCRIPTION--
The server sends a chunked response in several writes, with a chunk extension
and a trailer. The collector has no de-chunker: the body copy is what
libcurl's write function delivers, the de-chunked body, the same bytes the app
gets (G7.17). The trailer line reaches only the header function, after the
head is complete, and is ignored (G5.6). The body is complete: curl_exec()
reported no error.
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
$ch = curl_init("$base/chunked");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch);
?>
--EXPECT--
string(15) "0123456789abcde"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-type=text/plain, transfer-encoding=chunked]
  resp body: '0123456789abcde' complete=yes truncated=no
