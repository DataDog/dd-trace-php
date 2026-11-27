--TEST--
Downstream curl requests: with Expect, the read-function gate reads after 100 Continue, and not at all after a final status
--DESCRIPTION--
G6.7 exception: with "Expect: 100-continue" libcurl reads the body only
after the server's "100 Continue" (or the one-second timeout), and not at
all if the server answers a final status: then the app's read function is
never called, the gate holds nothing and makes no call; the hop gets its
response call. With 100 Continue the gate reads the body then and analyses
it (the control).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
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

foreach (['final', 'recv'] as $path) {
    $pieces = ['{"a":"expect"}'];
    $calls = 0;
    $ch = curl_init("$base/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json',
        'Expect: 100-continue']);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILESIZE, 14);
    curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$calls) {
        $calls++;
        return (string)array_shift($pieces);
    });
    echo "$path: ", var_export(curl_exec($ch), true), ", read function called $calls time(s)\n";
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
final: 'final without 100', read function called 0 time(s)
recv: 'received 14 bytes', read function called 1 time(s)
bool(true)
== server log
PUT /final
body not read
PUT /recv
body 14 bytes
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"expect":["100-continue"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/final"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["17"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"expect":["100-continue"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-2-0 {"server.io.net.request.body":{"a":"expect"}}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["17"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
