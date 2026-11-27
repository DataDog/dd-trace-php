--TEST--
Downstream curl requests: array POSTFIELDS keys are kept verbatim
--DESCRIPTION--
G7.7: the names of the fields of an array CURLOPT_POSTFIELDS are not split,
nested or mangled as PHP does for incoming variables: user[name] stays
user[name], a.b stays a.b, a space stays a space. Numeric string keys become
integer keys, as in any PHP array.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 2, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS,
    ['user[name]' => '1', 'a.b' => '2', 'a b' => '3', '123' => '4', 'x[]' => '5']);
curl_exec($ch);
var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (!$last) {
        // the order of mixed int and string keys depends on the PHP version
        $body = $data['server.io.net.request.body'];
        ksort($body, SORT_STRING);
        echo json_encode($body), "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
{"123":"4","a b":"3","a.b":"2","user[name]":"1","x[]":"5"}
