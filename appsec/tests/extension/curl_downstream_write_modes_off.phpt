--TEST--
Downstream curl requests: the app's data is unaffected by observation (off)
--DESCRIPTION--
G10.14: RETURNTRANSFER, default output, CURLOPT_FILE, WRITEFUNCTION with
HEADERFUNCTION, and CURLOPT_WRITEHEADER, for plain, chunked, large, gzip
(raw and decoded), redirected and 100-continue responses, a connection
error, one HTTPS (through a proxy tunnel) and one h2c case: the return value,
the output, the error number and string. With AppSec disabled, nothing is
observed (the reference output);
curl_downstream_write_modes_on.phpt and curl_downstream_write_modes_off.phpt
expect the same app-visible output.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// the HTTPS and h2c cases (G10.14)
if (!(curl_version()['features'] & CURL_VERSION_SSL)) die('skip libcurl without TLS');
if (!(curl_version()['features'] & CURL_VERSION_HTTP2)) die('skip libcurl without HTTP/2');
if (!extension_loaded('openssl')) die('skip the test server needs ext/openssl for TLS');
?>
--INI--
datadog.appsec.enabled=0
--FILE--
<?php
require __DIR__ . '/inc/curl_write_modes.php';
$observed = 0;
foreach ($handles as $ch) {
    $d = datadog\appsec\testing\curl_downstream_dump($ch);
    $observed += $d !== null && $d['observed'];
}
echo "observed: $observed of ", count($handles), "\n";
?>
--EXPECT--
/json RETURNTRANSFER: 22 570eaed39175f4c371ddcb13137b5791
/json stdout: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
/json FILE: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
/json WRITEFUNCTION: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791 calls>0=true
  headers: HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
/json WRITEHEADER: 22 570eaed39175f4c371ddcb13137b5791 header bytes: 90 934724001644857857d13715344ba03f
/chunked RETURNTRANSFER: 15 32a120cc6c02e2be926b4c785c440cd8
/chunked stdout: true errno=0 error="" 15 32a120cc6c02e2be926b4c785c440cd8
/chunked FILE: true errno=0 error="" 15 32a120cc6c02e2be926b4c785c440cd8
/chunked WRITEFUNCTION: true errno=0 error="" 15 32a120cc6c02e2be926b4c785c440cd8 calls>0=true
  headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close | X-Trailer: t
/chunked WRITEHEADER: 15 32a120cc6c02e2be926b4c785c440cd8 header bytes: 106 6987e0f48466d6df03675236ea31ce34
/big?n=70000 RETURNTRANSFER: 70000 f91a28da786c611817111cec85a66344
/big?n=70000 stdout: true errno=0 error="" 70000 f91a28da786c611817111cec85a66344
/big?n=70000 FILE: true errno=0 error="" 70000 f91a28da786c611817111cec85a66344
/big?n=70000 WRITEFUNCTION: true errno=0 error="" 70000 f91a28da786c611817111cec85a66344 calls>0=true
  headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Length: 70000 | Connection: close
/big?n=70000 WRITEHEADER: 70000 f91a28da786c611817111cec85a66344 header bytes: 87 b6ba97e79acbe48ea93fc99b0807b816
/gzip RETURNTRANSFER: 37 8722dc30b48aab9473d26272cd12c7b4
/gzip stdout: true errno=0 error="" 37 8722dc30b48aab9473d26272cd12c7b4
/gzip FILE: true errno=0 error="" 37 8722dc30b48aab9473d26272cd12c7b4
/gzip WRITEFUNCTION: true errno=0 error="" 37 8722dc30b48aab9473d26272cd12c7b4 calls>0=true
  headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Encoding: gzip | Content-Length: 37 | Connection: close
/gzip WRITEHEADER: 37 8722dc30b48aab9473d26272cd12c7b4 header bytes: 108 b96786172d43d568d5fe43e7253d9dee
/gzip decoded RETURNTRANSFER: 17 dd9300b8154179810955e06574636a7d
/gzip decoded stdout: true errno=0 error="" 17 dd9300b8154179810955e06574636a7d
/gzip decoded FILE: true errno=0 error="" 17 dd9300b8154179810955e06574636a7d
/gzip decoded WRITEFUNCTION: true errno=0 error="" 17 dd9300b8154179810955e06574636a7d calls>0=true
  headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Encoding: gzip | Content-Length: 37 | Connection: close
/gzip decoded WRITEHEADER: 17 dd9300b8154179810955e06574636a7d header bytes: 108 b96786172d43d568d5fe43e7253d9dee
/redirect RETURNTRANSFER: 22 570eaed39175f4c371ddcb13137b5791
/redirect stdout: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
/redirect FILE: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
/redirect WRITEFUNCTION: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791 calls>0=true
  headers: HTTP/1.1 302 Found | Location: /json | Content-Type: text/plain | Content-Length: 11 | Connection: close | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
/redirect WRITEHEADER: 22 570eaed39175f4c371ddcb13137b5791 header bytes: 194 dcb9736b139059aaa14e4cce48ad59d9
/len 100-continue RETURNTRANSFER: 16 807ac8fb8aac95180f85ebb68488fbbd
/len 100-continue stdout: true errno=0 error="" 16 807ac8fb8aac95180f85ebb68488fbbd
/len 100-continue FILE: true errno=0 error="" 16 807ac8fb8aac95180f85ebb68488fbbd
/len 100-continue WRITEFUNCTION: true errno=0 error="" 16 807ac8fb8aac95180f85ebb68488fbbd calls>0=true
  headers: HTTP/1.1 100 Continue | HTTP/1.1 200 OK | Content-Type: text/plain | Content-Length: 16 | Connection: close
/len 100-continue WRITEHEADER: 16 807ac8fb8aac95180f85ebb68488fbbd header bytes: 109 c53b8dc7b686e3a8445a7660f355b5ad
connection error RETURNTRANSFER: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server"
connection error stdout: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server" 0 d41d8cd98f00b204e9800998ecf8427e
connection error FILE: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server" 0 d41d8cd98f00b204e9800998ecf8427e
connection error WRITEFUNCTION: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server" 0 d41d8cd98f00b204e9800998ecf8427e calls>0=false
  headers: 
connection error WRITEHEADER: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server" header bytes: 0 d41d8cd98f00b204e9800998ecf8427e
https RETURNTRANSFER: 22 570eaed39175f4c371ddcb13137b5791
https stdout: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
https FILE: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791
https WRITEFUNCTION: true errno=0 error="" 22 570eaed39175f4c371ddcb13137b5791 calls>0=true
  headers: HTTP/1.1 200 Connection established | X-Proxy: tunnel | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
https WRITEHEADER: 22 570eaed39175f4c371ddcb13137b5791 header bytes: 146 a3467d7f967a045bac34102e528599be
h2c RETURNTRANSFER: 10 614455e2bb8fcc693a1b14408e5afda2
h2c stdout: true errno=0 error="" 10 614455e2bb8fcc693a1b14408e5afda2
h2c FILE: true errno=0 error="" 10 614455e2bb8fcc693a1b14408e5afda2
h2c WRITEFUNCTION: true errno=0 error="" 10 614455e2bb8fcc693a1b14408e5afda2 calls>0=true
  headers: HTTP/2 200 | content-type: text/plain | content-length: 10 | x-main: yes
h2c WRITEHEADER: 10 614455e2bb8fcc693a1b14408e5afda2 header bytes: 74 a0a8ef963efe708ece589088c0b3c2b5
observed: 0 of 50
