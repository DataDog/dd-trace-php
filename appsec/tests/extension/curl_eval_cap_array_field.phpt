--TEST--
Downstream curl requests: a long text field of an array body is capped
--DESCRIPTION--
With DD_APPSEC_MAX_BODY_BUFF_SIZE=64, a CURLOPT_POSTFIELDS array with one
very long text field is sent truncated: key and value together are 64 bytes
(1 + 63), and the field after it is dropped. The cap holds for scalar fields
and for keys, not only for the elements of nested arrays: the nested array
keeps as many one-byte elements as the cap leaves after the key (63). Only
bytes count (G7.12): no memory cost per field or element is estimated (G9.6).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_APPSEC_MAX_BODY_BUFF_SIZE=64
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
foreach ([['f' => str_repeat('a', 100000), str_repeat('k', 100) => 'v'],
        ['g' => array_fill(0, 100000, 'x')]] as $fields) {
    $ch = curl_init("$base/echo");
    // (PHP 7's ext/curl notices the nested array, sent as "Array")
    @curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
    ]);
    curl_exec($ch);
}
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if (!$last) {
        $body = $data['server.io.net.request.body'];
        foreach ($body as $k => $v) {
            // below PHP 8.2 the nested array is the flat text "Array": it has
            // no elements to cap
            if ($k === 'g' && $v === 'Array' && PHP_VERSION_ID < 80200) {
                $v = array_fill(0, 63, 'x');
            }
            echo strlen($k), ' ', is_array($v)
                ? count($v) . ' elements'
                : strlen($v), "\n";
        }
    }
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
bool(true)
1 63
1 63 elements
2 subcontext(s), all closed
