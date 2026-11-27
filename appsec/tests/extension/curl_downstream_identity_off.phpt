--TEST--
Downstream curl requests: response data reaches the app unchanged (off)
--DESCRIPTION--
The reference for curl_downstream_identity_on.phpt (G10.14): AppSec disabled.
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
require __DIR__ . '/inc/curl_downstream_identity.php';
$base = cd_server_start();
ch_identity_cases($base);
?>
--EXPECT--
json: curl_exec: true errno=0 error=""
json: headers: HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
json: blank header lines: 1
json: body: 22 570eaed39175f4c371ddcb13137b5791
json: write calls: 22
json: called from: {closure}<curl_exec<ch_run
chunked: curl_exec: true errno=0 error=""
chunked: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close | X-Trailer: t
chunked: blank header lines: 1
chunked: body: 15 32a120cc6c02e2be926b4c785c440cd8
chunked: write calls: 10,5
chunked: called from: {closure}<curl_exec<ch_run
gzip-raw: curl_exec: true errno=0 error=""
gzip-raw: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Encoding: gzip | Content-Length: 37 | Connection: close
gzip-raw: blank header lines: 1
gzip-raw: body: 37 8722dc30b48aab9473d26272cd12c7b4
gzip-raw: write calls: 37
gzip-raw: called from: {closure}<curl_exec<ch_run
gzip-decoded: curl_exec: true errno=0 error=""
gzip-decoded: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Encoding: gzip | Content-Length: 37 | Connection: close
gzip-decoded: blank header lines: 1
gzip-decoded: body: 17 dd9300b8154179810955e06574636a7d
gzip-decoded: write calls: 17
gzip-decoded: called from: {closure}<curl_exec<ch_run
redirect: curl_exec: true errno=0 error=""
redirect: headers: HTTP/1.1 302 Found | Location: /json | Content-Type: text/plain | Content-Length: 11 | Connection: close | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
redirect: blank header lines: 2
redirect: body: 22 570eaed39175f4c371ddcb13137b5791
redirect: write calls: 22
redirect: called from: {closure}<curl_exec<ch_run
redirect-nofollow: curl_exec: true errno=0 error=""
redirect-nofollow: headers: HTTP/1.1 302 Found | Location: /json | Content-Type: text/plain | Content-Length: 11 | Connection: close
redirect-nofollow: blank header lines: 1
redirect-nofollow: body: 11 9b3d772e4ad71b25effd8bd93a938c61
redirect-nofollow: write calls: 11
redirect-nofollow: called from: {closure}<curl_exec<ch_run
continue: curl_exec: true errno=0 error=""
continue: headers: HTTP/1.1 100 Continue | HTTP/1.1 200 OK | Content-Type: text/plain | Content-Length: 16 | Connection: close
continue: blank header lines: 2
continue: body: 16 807ac8fb8aac95180f85ebb68488fbbd
continue: write calls: 16
continue: called from: {closure}<curl_exec<ch_run
close: curl_exec: true errno=0 error=""
close: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Connection: close
close: blank header lines: 1
close: body: 16 d2af0a7a4c3063840ffd895fbcb3f4f0
close: write calls: 16
close: called from: {closure}<curl_exec<ch_run
big: curl_exec: true errno=0 error=""
big: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Length: 200000 | Connection: close
big: blank header lines: 1
big: body: 200000 bcc4bfca43786497d970f7d3e2fdd455
big: called from: {closure}<curl_exec<ch_run
xml: curl_exec: true errno=0 error=""
xml: headers: HTTP/1.1 200 OK | Content-Type: application/xml | Content-Length: 37 | Connection: close
xml: blank header lines: 1
xml: body: 37 b1b0a2b36dddcfcf3c12b6a2df491fbe
xml: write calls: 37
xml: called from: {closure}<curl_exec<ch_run
header-in-body: curl_exec: true errno=0 error=""
header-in-body: headers: HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
header-in-body: blank header lines: 1
header-in-body: body: 112 fe377316e436521fb628639071478168
header-in-body: write calls: 17,32,20,19,2,22
header-in-body: called from: {closure}<curl_exec<ch_run
header-only: curl_exec: string 32a120cc6c02e2be926b4c785c440cd8 errno=0 error=""
header-only: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close | X-Trailer: t
header-only: blank header lines: 1
header-only: body: 0 d41d8cd98f00b204e9800998ecf8427e
header-only: write calls: 
header-only: called from: {closure}<curl_exec<ch_run
write-only: curl_exec: true errno=0 error=""
write-only: headers: 
write-only: blank header lines: 0
write-only: body: 15 32a120cc6c02e2be926b4c785c440cd8
write-only: write calls: 10,5
write-only: called from: {closure}<curl_exec<ch_run
status-204: curl_exec: true errno=0 error=""
status-204: headers: HTTP/1.1 204 No Content | Connection: close
status-204: blank header lines: 1
status-204: body: 0 d41d8cd98f00b204e9800998ecf8427e
status-204: write calls: 
status-204: called from: {closure}<curl_exec<ch_run
head: curl_exec: true errno=0 error=""
head: headers: HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
head: blank header lines: 1
head: body: 0 d41d8cd98f00b204e9800998ecf8427e
head: write calls: 
head: called from: {closure}<curl_exec<ch_run
paced: curl_exec: true errno=0 error=""
paced: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Content-Length: 10 | Connection: close
paced: blank header lines: 1
paced: body: 10 781e5e245d69b566979b86e28d23f2c7
paced: write calls: 10
paced: called from: {closure}<curl_exec<ch_run
abort-write: curl_exec: false errno=23 error="Failure writing output to destination"
abort-write: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close
abort-write: blank header lines: 1
abort-write: body: 10 781e5e245d69b566979b86e28d23f2c7
abort-write: write calls: 10
abort-write: called from: {closure}<curl_exec<ch_run
abort-header: curl_exec: false errno=23 error="Failed writing header"
abort-header: headers: HTTP/1.1 200 OK
abort-header: blank header lines: 0
abort-header: body: 0 d41d8cd98f00b204e9800998ecf8427e
abort-header: write calls: 
abort-header: called from: {closure}<curl_exec<ch_run
connection-error: curl_exec: false errno=7 error="Failed to connect to 127.0.0.1 port PORT after N ms: Couldn't connect to server"
connection-error: headers: 
connection-error: blank header lines: 0
connection-error: body: 0 d41d8cd98f00b204e9800998ecf8427e
connection-error: write calls: 
copy: curl_exec: true errno=0 error=""
copy: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close | X-Trailer: t
copy: blank header lines: 1
copy: body: 15 32a120cc6c02e2be926b4c785c440cd8
copy: write calls: 10,5
copy: called from: {closure}<curl_exec<ch_run
clone: curl_exec: true errno=0 error=""
clone: headers: HTTP/1.1 302 Found | Location: /json | Content-Type: text/plain | Content-Length: 11 | Connection: close | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
clone: blank header lines: 2
clone: body: 22 570eaed39175f4c371ddcb13137b5791
clone: write calls: 22
clone: called from: {closure}<curl_exec<ch_run
  multi result=0
multi: curl_exec: true errno=0 error=""
multi: headers: HTTP/1.1 200 OK | Content-Type: text/plain | Transfer-Encoding: chunked | Connection: close | X-Trailer: t
multi: blank header lines: 1
multi: body: 15 32a120cc6c02e2be926b4c785c440cd8
multi: write calls: 10,5
multi: called from: {closure}<curl_multi_exec<ch_run
  multi result=0
multi-redirect: curl_exec: true errno=0 error=""
multi-redirect: headers: HTTP/1.1 302 Found | Location: /json | Content-Type: text/plain | Content-Length: 11 | Connection: close | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
multi-redirect: blank header lines: 2
multi-redirect: body: 22 570eaed39175f4c371ddcb13137b5791
multi-redirect: write calls: 22
multi-redirect: called from: {closure}<curl_multi_exec<ch_run
https: curl_exec: true errno=0 error=""
https: headers: HTTP/1.1 200 Connection established | X-Proxy: tunnel | HTTP/1.1 200 OK | Content-Type: application/json | Content-Length: 22 | Connection: close
https: blank header lines: 2
https: body: 22 570eaed39175f4c371ddcb13137b5791
https: write calls: 22
https: called from: {closure}<curl_exec<ch_run
h2c: curl_exec: true errno=0 error=""
h2c: headers: HTTP/2 200 | content-type: text/plain | content-length: 10 | x-main: yes
h2c: blank header lines: 1
h2c: body: 10 614455e2bb8fcc693a1b14408e5afda2
h2c: write calls: 10
h2c: called from: {closure}<curl_exec<ch_run
