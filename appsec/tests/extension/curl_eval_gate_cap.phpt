--TEST--
Downstream curl requests: the read-function gate analyses the body up to the cap; the rest flows on unanalysed
--DESCRIPTION--
G6.7, G7.15: with DD_APPSEC_MAX_BODY_BUFF_SIZE=64 the gate reads the body
until it holds 64 bytes (or the body ends), analyses those, then serves what
it holds and forwards the rest: the pattern past the cap is not analysed,
and the server gets the whole body, with the length and content the app
supplied. From the app's READFUNCTION in small pieces (several reads fill
the buffer) and from a large INFILE (the read that crosses the cap brings
bytes past it, which are served unanalysed).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
DD_APPSEC_MAX_BODY_BUFF_SIZE=64
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());
$ct = ['Content-Type: application/x-www-form-urlencoded'];

// READFUNCTION, 10 bytes at a time, chunked POST
$body = 'a=' . str_repeat('x', 70) . '&b=attack';
$pieces = str_split($body, 10);
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($ct, ['Transfer-Encoding: chunked']));
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces) {
    return (string)array_shift($pieces);
});
var_dump(curl_exec($ch) === $body);

// INFILE, 200000 bytes
$body2 = 'a=' . str_repeat('y', 200000 - 11) . '&b=attack';
$infile = tempnam(sys_get_temp_dir(), 'cdin');
file_put_contents($infile, $body2);
$fp = fopen($infile, 'rb');
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $ct);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILE, $fp);
curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body2));
$res = curl_exec($ch);
var_dump(strlen($res), $res === $body2);
fclose($fp);
@unlink($infile);

var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.body'])) {
        echo "$id body: ", json_encode($data['server.io.net.request.body']), "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
int(200000)
bool(true)
bool(true)
curl-1-0 body: {"a":"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"}
curl-2-0 body: {"a":"yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"}
