--TEST--
A large downstream response does not mark the main request as truncated
--DESCRIPTION--
The response body (a JSON object with one long string) is sent as the
converter's msgpack, so the serialiser's string limit (4096) does not cut it
(G7.10). The serialiser's truncation of the other addresses belongs to the
downstream call anyway: input_truncated of request_shutdown stays false.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response body');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/big?n=10000&json=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(strlen(curl_exec($ch)));
var_dump(rshutdown());
$len = 0;
foreach ($helper->get_commands() as $c) {
    if ($c[0] === 'request_exec' && isset($c[1][0]['server.io.net.response.body'])) {
        $len = strlen($c[1][0]['server.io.net.response.body']['a']);
    }
    if ($c[0] === 'request_shutdown') {
        echo "input_truncated: "; var_dump($c[1][2]);
    }
}
echo "body sent: $len\n";
?>
--EXPECT--
bool(true)
int(10000)
bool(true)
input_truncated: bool(false)
body sent: 9992
