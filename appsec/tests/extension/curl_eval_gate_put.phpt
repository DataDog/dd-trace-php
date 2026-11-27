--TEST--
Downstream curl requests: the read-function gate analyses a PUT body from CURLOPT_INFILE or CURLOPT_READFUNCTION
--DESCRIPTION--
G6.7, G7.15: an upload (CURLOPT_UPLOAD) with CURLOPT_INFILESIZE > 0 whose
body comes from CURLOPT_INFILE (a plain file, which ext/curl casts to a
FILE *) or from the app's CURLOPT_READFUNCTION, with no CURLOPT_POSTFIELDS,
in a sampled transfer: the interposed read function reads the body through
ext/curl's read function before any of it is sent, and a request-stage call
in hop 0's subcontext (after W1, before the response call) carries the
parsed body. The server still gets the whole body.
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());

$body = '{"a":"from infile","n":[1,2]}';
$infile = tempnam(sys_get_temp_dir(), 'cdin');
file_put_contents($infile, $body);
$fp = fopen($infile, 'rb');
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILE, $fp);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
var_dump(curl_exec($ch) === $body);
fclose($fp);
@unlink($infile);

$body2 = '{"a":"from readfunction"}';
$pieces = ['{"a":"from ', 'readfunction"}'];
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body2));
$calls = 0;
curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $len) use (&$pieces, &$calls) {
    $calls++;
    return (string)array_shift($pieces);
});
var_dump(curl_exec($ch) === $body2);
// the gate reads up to the size libcurl sends, not past it: as many calls
// as libcurl makes without it
echo "read function called $calls time(s)\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
read function called 2 time(s)
bool(true)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 {"server.io.net.request.body":{"a":"from infile","n":[1,2]}}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["29"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-2-0 {"server.io.net.request.body":{"a":"from readfunction"}}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["25"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
