--TEST--
Downstream curl requests: only JSON and XML responses provide a body
--DESCRIPTION--
G7.8: urlencoded, multipart, text/plain and every other response type, and a
response without Content-Type, provide no server.io.net.response.body, even
when the body would parse (k=v&n=1).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response body');
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
$paths = ['/form', '/multipart', '/octet', '/untyped', '/echo', '/json'];
$helper = ce_helper(array_fill(0, 2 * count($paths), 'ok'));
var_dump(rinit());
foreach ($paths as $path) {
    $ch = curl_init("$base$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
}
var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if ($last) {
        $n = (int)substr($id, 5);
        echo $paths[$n - 1], ': response body ',
            isset($data['server.io.net.response.body']) ? 'yes' : 'no', "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
/form: response body no
/multipart: response body no
/octet: response body no
/untyped: response body no
/echo: response body no
/json: response body yes
