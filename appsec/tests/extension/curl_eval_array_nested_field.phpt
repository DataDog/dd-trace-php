--TEST--
Downstream curl requests: an array-valued field of an array POSTFIELDS follows what ext/curl sends
--DESCRIPTION--
G7.7: below PHP 8.2, ext/curl stringifies an array-valued field to the text
"Array" (and raises a notice of its own; the hook raises none), so the body
holds the flat text field "Array" and nothing of the nested elements. From
PHP 8.2 it sends one multipart part per element under the outer field name,
and the body carries the list of the element values (an inner key is dropped;
an element that is itself an array is sent, with a notice, as the text
"Array", checked on the wire on 8.3 and 8.5).
Below 8.2 an object nested in an array-valued field is not looked at, so the
transfer is still analysed (G4.10 is about the direct elements of the array).
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
$old = PHP_VERSION_ID < 80200;
$cases = [
    'array-valued field' => [['a' => ['x', 'y'], 'b' => ['k' => 'z'], 'c' => 'd'],
        $old ? '{"a":"Array","b":"Array","c":"d"}'
             : '{"a":["x","y"],"b":["z"],"c":"d"}'],
];
// below 8.2 even an object in the nested array is not looked at
$cases['nested element kinds'] = $old
    ? [['a' => [new stdClass], 'c' => 'd'], '{"a":"Array","c":"d"}']
    : [['a' => [1, 'two', true]], '{"a":["1","two","1"]}'];
$cases['array element'] = [['a' => [['q'], 'x']],
    $old ? '{"a":"Array"}' : '{"a":["Array","x"]}'];
$helper = ce_helper(array_fill(0, 2 * count($cases), 'ok'));
var_dump(rinit());
foreach ($cases as $name => list($fields)) {
    $ch = curl_init("$base/echo");
    // (below PHP 8.2 ext/curl notices the nested array, sent as "Array")
    @curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
    ]);
    curl_exec($ch);
}
var_dump(rshutdown());
$execs = ce_execs($helper);
$names = array_keys($cases);
$i = 0;
foreach ($execs as list($id, $last, $data)) {
    if (!$last) {
        $got = json_encode($data['server.io.net.request.body'] ?? null);
        $exp = $cases[$names[$i]][1];
        echo $names[$i++], ': ', $got === $exp ? 'as ext/curl sends' : "got $got, expected $exp", "\n";
    }
}
echo $i === count($cases) ? "all transfers analysed\n" : "only $i of " . count($cases) . " transfers analysed\n";
?>
--EXPECT--
bool(true)
bool(true)
array-valued field: as ext/curl sends
nested element kinds: as ext/curl sends
array element: as ext/curl sends
all transfers analysed
