--TEST--
Downstream curl requests: a response body decoded by libcurl is analysed
--DESCRIPTION--
Response bytes are what libcurl's write function delivers (G7.17): with
CURLOPT_ACCEPT_ENCODING set, libcurl decodes a gzip body before the write
function, so the JSON response body is analysed although the head says
Content-Encoding: gzip. Without ACCEPT_ENCODING, or with
CURLOPT_HTTP_CONTENT_DECODING=0, the bytes stay compressed, fail to parse and
no response body is sent (exception to G5.7); the head is still evaluated.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
if (!(curl_version()['features'] & CURL_VERSION_LIBZ)) die('skip libcurl without zlib');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());

echo "== 1. ACCEPT_ENCODING: decoded by libcurl\n";
$ch = curl_init("$base/gzip-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_ACCEPT_ENCODING => '']);
var_dump(curl_exec($ch));

echo "== 2. no ACCEPT_ENCODING: compressed\n";
$ch = curl_init("$base/gzip-json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(bin2hex(substr(curl_exec($ch), 0, 2)));

echo "== 3. ACCEPT_ENCODING and HTTP_CONTENT_DECODING=0: compressed\n";
$ch = curl_init("$base/gzip-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_ACCEPT_ENCODING => '',
    CURLOPT_HTTP_CONTENT_DECODING => 0]);
var_dump(bin2hex(substr(curl_exec($ch), 0, 2)));

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== 1. ACCEPT_ENCODING: decoded by libcurl
string(22) "{"status":"ok","n":42}"
== 2. no ACCEPT_ENCODING: compressed
string(4) "1f8b"
== 3. ACCEPT_ENCODING and HTTP_CONTENT_DECODING=0: compressed
string(4) "1f8b"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/gzip-json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-encoding":["gzip"],"content-length":["42"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/gzip-json"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-encoding":["gzip"],"content-length":["42"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/gzip-json"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-encoding":["gzip"],"content-length":["42"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
