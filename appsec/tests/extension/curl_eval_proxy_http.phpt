--TEST--
Downstream curl requests to http:// URLs through an HTTP proxy are evaluated from the options
--DESCRIPTION--
Without a tunnel, libcurl sends a request for an http:// URL to the proxy
with the URL as its target ("GET http://host/path HTTP/1.1"), plus headers
for the proxy only (Proxy-Authorization from CURLOPT_PROXYUSERPWD). The
request is evaluated before it is sent, from the recorded options, so those
headers, which are no options of the request, never appear and nothing is
stripped: there is no request-sent call. The URL is the origin's, also after
a redirect; a Proxy-* line the app set in CURLOPT_HTTPHEADER is kept
(unfiltered).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// without it, no hop after the first is evaluated (G6.11)
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

echo "== 1. GET with proxy credentials\n";
$ch = curl_init('http://example.test/json?n=1');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_PROXYUSERPWD => 'user:secret']);
var_dump(curl_exec($ch));

echo "== 2. redirect, followed through the proxy\n";
$ch = curl_init('http://example.test/redirect');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_PROXYUSERPWD => 'user:secret', CURLOPT_FOLLOWLOCATION => true]);
var_dump(curl_exec($ch));

echo "== 3. Proxy-* line in CURLOPT_HTTPHEADER\n";
$ch = curl_init('http://example.test/json?n=3');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_HTTPHEADER => ['Proxy-Authorization: Basic YXBwOnNlY3JldA==']]);
var_dump(curl_exec($ch));

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
echo "== server log\n";
echo file_get_contents($log);
@unlink($log);
?>
--EXPECT--
bool(true)
== 1. GET with proxy credentials
string(22) "{"status":"ok","n":42}"
== 2. redirect, followed through the proxy
string(22) "{"status":"ok","n":42}"
== 3. Proxy-* line in CURLOPT_HTTPHEADER
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/json?n=1"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/redirect"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/json"]},"server.io.net.response.status":"302"}
curl-2-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/json"}
curl-2-1 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["example.test"],"proxy-authorization":["Basic YXBwOnNlY3JldA=="]},"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/json?n=3"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
4 subcontext(s), all closed
== server log
GET http://example.test/json?n=1
GET http://example.test/redirect
GET http://example.test/json
GET http://example.test/json?n=3
