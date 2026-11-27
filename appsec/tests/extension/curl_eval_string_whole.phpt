--TEST--
Downstream curl requests are evaluated natively: long strings of request bodies are not cut
--DESCRIPTION--
G7.10: the serialiser's string limit (4096) does not apply to a body carried
as the converter's msgpack. A JSON string of 6000 bytes in the request body
arrives whole when the body comes from CURLOPT_POSTFIELDS (the presend call),
from CURLOPT_READFUNCTION (the read gate's call) and, for XML, as the text of
an element.
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
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 12, 'ok'));
rinit();
$long = str_repeat('x', 6000);
$json = '{"a":"' . $long . '"}';
$cmds = [];

$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
curl_exec($ch);
$cmds = array_merge($cmds, (array)$helper->get_commands());

$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Transfer-Encoding: chunked',
    'Content-Type: application/json']);
$pieces = [substr($json, 0, 3000), substr($json, 3000)];
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces) {
    return (string)array_shift($pieces);
});
curl_exec($ch);
$cmds = array_merge($cmds, (array)$helper->get_commands());

$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml']);
curl_setopt($ch, CURLOPT_POSTFIELDS, "<r>$long</r>");
curl_exec($ch);
rshutdown();
$cmds = array_merge($cmds, (array)$helper->get_commands());

foreach (ce_execs_of($cmds) as list($id, $last, $data)) {
    if (!isset($data['server.io.net.request.body'])) {
        continue;
    }
    $b = $data['server.io.net.request.body'];
    $s = $b['a'] ?? ($b['r'][0] ?? null);
    echo "$id: ", is_string($s) ? strlen($s) : json_encode($b), "\n";
}
?>
--EXPECT--
curl-1-0: 6000
curl-2-0: 6000
curl-3-0: 6000
