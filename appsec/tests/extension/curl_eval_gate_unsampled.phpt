--TEST--
Downstream curl requests: an unsampled transfer arms no read-function gate
--DESCRIPTION--
G6.7 exception: with the sample rate 0 (G8.4) no transfer is sampled, so
the read-function gate is not armed: ext/curl's read function serves the
body straight through (the app's callable sees the gate off), nothing is
held and no request-stage call carries the body. W1 and the response call
are made as for any transfer.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());

$pieces = ['{"a":', '"unsampled"}'];
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, 17);
$gate = [];
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$gate) {
    $gate[] = datadog\appsec\testing\curl_downstream_dump($h)['gate'];
    return (string)array_shift($pieces);
});
var_dump(curl_exec($ch));
echo 'gate during the reads: ', implode(', ', array_unique($gate)), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
string(17) "{"a":"unsampled"}"
gate during the reads: off
bool(true)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["17"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
