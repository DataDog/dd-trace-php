--TEST--
Downstream curl requests: urlencoded string bodies are parsed whatever the method
--DESCRIPTION--
A string CURLOPT_POSTFIELDS of type application/x-www-form-urlencoded is
parsed as parse_str() would for PUT, PATCH and DELETE too, not only for POST
(G7.6). Other content types (octet-stream) give no body, and the response of
the test server (text/plain) gives no response body (G7.8). A body that is
empty after parsing (G7.18) adds no address: a failed parse, "null" and a
urlencoded body without fields; but "{}" is a value.
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
$cases = [
    ['PUT', 'application/x-www-form-urlencoded', 'a=1&b[]=2&b[]=3'],
    ['PATCH', 'application/x-www-form-urlencoded', 'a=1&b[x]=2'],
    ['DELETE', 'application/x-www-form-urlencoded', 'a=1&c=%2B'],
    ['POST', 'application/octet-stream', 'a=1&d=2'],
    ['POST', 'application/json', '{}'],
    ['POST', 'application/json', 'null'],
    ['POST', 'application/json', 'not json'],
    ['POST', 'application/x-www-form-urlencoded', '&&='],
];
$helper = ce_helper(array_fill(0, 2 * count($cases), 'ok'));
var_dump(rinit());
foreach ($cases as list($method, $ct, $body)) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ["Content-Type: $ct"],
    ]);
    curl_exec($ch);
}
var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.method'])) {
        echo $data['server.io.net.request.method'], ' ',
            json_encode(isset($data['server.io.net.request.body']) ?
                $data['server.io.net.request.body'] : null), "\n";
    }
    if (isset($data['server.io.net.response.body'])) {
        echo "unexpected response body\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
PUT {"a":"1","b":["2","3"]}
PATCH {"a":"1","b":{"x":"2"}}
DELETE {"a":"1","c":"+"}
POST null
POST []
POST null
POST null
POST null
