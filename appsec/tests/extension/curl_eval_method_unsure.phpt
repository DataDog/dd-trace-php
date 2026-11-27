--TEST--
Downstream curl requests: W1 omits the method and body it is not sure of
--DESCRIPTION--
When the options leave the method unsure (before libcurl 8.1.0, CURLOPT_UPLOAD
makes every request a PUT whatever is set afterwards; reproduced here on any
libcurl by faking the version while the options are recorded), W1 has neither
server.io.net.request.method nor server.io.net.request.body (G6.3), while the
URL and the headers are still sent. A CURLOPT_CUSTOMREQUEST is sent
regardless: libcurl sends it unchanged.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_linkage_fake_version};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 12, 'ok'), false);
var_dump(rinit());
ce_execs($helper); // request_init

$cases = ['unsure' => null, 'custom request' => 'PATCH'];
foreach ($cases as $label => $custom) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $real = curl_linkage_fake_version(0x080000); // before 8.1.0
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{"a":1}');
    curl_linkage_fake_version($real);
    if ($custom !== null) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $custom);
    }
    curl_exec($ch);
    ce_print([ce_execs($helper)[0]]);
}
var_dump(rshutdown());
?>
--EXPECTF--
bool(true)
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PATCH","server.io.net.url":"http://127.0.0.1:PORT/echo"}
bool(true)
