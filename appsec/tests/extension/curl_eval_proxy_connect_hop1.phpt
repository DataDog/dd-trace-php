--TEST--
Downstream curl requests: the CONNECT head of a later hop does not replace the previous hop's head
--DESCRIPTION--
A redirect followed through an HTTP proxy tunnel: the server closes every
connection, so libcurl opens a new tunnel for hop 1. The proxy's "200
Connection established" head (with X-Proxy: tunnel) reaches the header
function after hop 0's final head (the 302) and before hop 1's PREREQ. By
G5.6 and G5.11 header lines after a complete head are ignored until the next
PREREQ, so the response call of hop 0, made in hop 1's PREREQ, carries the
302 head, and hop 1's head is the origin's 200, not the tunnel's. The tunnel
head of hop 0 (before its PREREQ) is dropped too.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('hops >= 1');
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
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 6, 'ok'));
var_dump(rinit());

$ch = curl_init('http://example.test/redirect-to?u=' . urlencode('http://other.test/json'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_HTTPPROXYTUNNEL => true, CURLOPT_FOLLOWLOCATION => true]);
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
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/redirect-to?u=http%3A%2F%2Fother.test%2Fjson"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["http://other.test/json"]},"server.io.net.response.status":"302"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://other.test/json"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
== server log
CONNECT example.test:80
GET /redirect-to?u=http%3A%2F%2Fother.test%2Fjson
CONNECT other.test:80
GET /json
