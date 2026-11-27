--TEST--
Downstream curl requests: the read-function gate analyses a chunked POST body from CURLOPT_READFUNCTION
--DESCRIPTION--
G6.7, G7.15: a POST whose body the app's CURLOPT_READFUNCTION supplies, sent
chunked (no size known), in a sampled transfer: the interposed read function
reads the whole body through ext/curl's read function (the app's callable,
until it returns ""), makes the request-stage call with the parsed body in
hop 0's subcontext, then serves the bytes it holds. The server gets the
body unchanged.
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

$pieces = ['a=1&b=', str_repeat('x', 3000), '&c=chunked'];
$body = implode('', $pieces);
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Transfer-Encoding: chunked',
    'Content-Type: application/x-www-form-urlencoded']);
$calls = 0;
$gate = [];
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$calls, &$gate) {
    $calls++;
    $gate[] = datadog\appsec\testing\curl_downstream_dump($h)['gate'];
    return (string)array_shift($pieces);
});
$res = curl_exec($ch);
var_dump($res === $body, $calls);
// the gate holds the body while the app's callable supplies it
echo 'gate during the reads: ', implode(', ', array_unique($gate)), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as &$e) {
    if (isset($e[2]['server.io.net.request.body']['b'])) {
        $b = $e[2]['server.io.net.request.body']['b'];
        $e[2]['server.io.net.request.body']['b'] = strlen($b) . ' bytes, ' .
            ($b === str_repeat('x', 3000) ? 'as sent' : 'not as sent');
    }
}
unset($e);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
bool(true)
int(4)
gate during the reads: filling
bool(true)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/x-www-form-urlencoded"],"host":["127.0.0.1:PORT"],"transfer-encoding":["chunked"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 {"server.io.net.request.body":{"a":"1","b":"3000 bytes, as sent","c":"chunked"}}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["3016"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
