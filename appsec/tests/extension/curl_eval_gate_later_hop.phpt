--TEST--
Downstream curl requests: the read-function gate covers hop 0 only, a later hop's body goes straight through
--DESCRIPTION--
G6.7 exception, G7.9, G7.15: a sampled PUT whose body comes from the app's
CURLOPT_READFUNCTION, to a route that answers 307, with
CURLOPT_FOLLOWLOCATION.

With "Expect: 100-continue" the route answers 307 at once, without reading
the body: libcurl sends the body only in hop 1. The PREREQ call of hop 1
turns the gate off, so the app's callable sees the gate off, the body goes
to the server whole, and no request-stage call carries it: hop 0 read no
body, and the body of a hop >= 1 is never analysed. Hop 1 gets W2 and the
response calls as usual.

Without Expect the route reads the body first: the gate reads and analyses
it in hop 0, and the 307 resend fails in libcurl, which cannot rewind a
read function without a seek function (ext/curl sets none):
CURLE_SEND_FAIL_REWIND. So only hop 0 has a body call.

Below libcurl 7.80 there is no PREREQ and libcurl keeps the gate (G6.11).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('turning the gate off at a later hop');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());

foreach (['Expect: 100-continue', 'Expect:'] as $expect) {
    $pieces = ['{"a":', '"hop n"}'];
    $ch = curl_init("$base/final-to?u=/recv");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $expect]);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, 13);
    $gate = [];
    curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$gate) {
        $gate[] = datadog\appsec\testing\curl_downstream_dump($h)['gate'];
        return (string)array_shift($pieces);
    });
    echo "== $expect\n";
    var_dump(curl_exec($ch), curl_errno($ch));
    echo 'gate during the reads: ', implode(', ', $gate), "\n";
}

var_dump(rshutdown());
echo "== server log\n", file_get_contents($log);
@unlink($log);
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== Expect: 100-continue
string(17) "received 13 bytes"
int(0)
gate during the reads: off, off
== Expect:
bool(false)
int(65)
gate during the reads: filling, filling
bool(true)
== server log
PUT /final-to?u=/recv
body not read
PUT /recv
body 13 bytes
PUT /final-to?u=/recv
body 13 bytes
(no request bytes)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"expect":["100-continue"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/final-to?u=/recv"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/recv"]},"server.io.net.response.status":"307"}
curl-1-1 {"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-1-1 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["17"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/final-to?u=/recv"}
curl-2-0 {"server.io.net.request.body":{"a":"hop n"}}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["/recv"]},"server.io.net.response.status":"307"}
3 subcontext(s), all closed
