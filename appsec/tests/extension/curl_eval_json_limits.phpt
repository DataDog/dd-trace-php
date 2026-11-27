--TEST--
Downstream curl requests are evaluated natively: JSON bodies keep the parser's limits only
--DESCRIPTION--
A JSON body is carried as the msgpack the entity-body converter produced, so
it does not go through the serialiser's limits (depth 21, 2048 elements): a body nested 25 deep and an array of 3000 elements
reach the helper intact, for the request and for the response (G7.10). A body
nested 35 deep is still cut at the parser's own depth limit of 30. One
transfer per shape.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response body');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=4
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';

function shape($b)
{
    if (isset($b['w'])) {
        return count($b['w']) . ' elements, last ' . end($b['w']);
    }
    for ($d = 0; is_array($b) && isset($b['a']); $d++) {
        $b = $b['a'];
    }
    return "depth $d, leaf " . json_encode($b);
}

$shapes = ['deep' => str_repeat('{"a":', 25) . '"leaf"' . str_repeat('}', 25),
    'wide' => json_encode(['w' => range(1, 3000)]),
    // beyond the parser's own depth limit (30)
    'toodeep' => str_repeat('{"a":', 35) . '"leaf"' . str_repeat('}', 35)];

$base = cd_server_start();
$helper = ce_helper(array_fill(0, 6, 'ok'));
rinit();
$cmds = [];
foreach ($shapes as $name => $json) {
    $ch = curl_init("$base/echo-json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    var_dump(strlen(curl_exec($ch)) === strlen($json));
    // drain the pipe the mock helper echoes the calls to
    $cmds = array_merge($cmds, (array)$helper->get_commands());
}
rshutdown();
$cmds = array_merge($cmds, (array)$helper->get_commands());
foreach (ce_execs_of($cmds) as list($id, $last, $data)) {
    foreach (['request', 'response'] as $dir) {
        if (isset($data["server.io.net.$dir.body"])) {
            echo "$id $dir: ", shape($data["server.io.net.$dir.body"]), "\n";
        }
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
curl-1-0 request: depth 25, leaf "leaf"
curl-1-0 response: depth 25, leaf "leaf"
curl-2-0 request: 3000 elements, last 3000
curl-2-0 response: 3000 elements, last 3000
curl-3-0 request: depth 30, leaf []
curl-3-0 response: depth 30, leaf []
