--TEST--
Downstream curl requests: urlencoded bodies follow max_input_nesting_level without a warning
--DESCRIPTION--
With a low max_input_nesting_level the native splitter drops the names nested
too deep exactly as parse_str() does (the whole base name goes, whatever was
registered under it before), at the exact bracket group where PHP's loop would
give up, but without the E_WARNING that PHP raises (with display_errors off)
and which the app's error handler, here throwing, would get.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
display_errors=0
max_input_nesting_level=3
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$fixtures = [
    'a[b][c]=1&keep=1',
    'a[b][c][d]=1&keep=1',
    'a[b][c][d][e]=1&keep=1',
    'a=0&a[b][c][d][e]=1&keep=1',
    'a[x]=1&a[b][c][d][e]=1&keep=1',
    'a.b c[x][y][z][w]=1&a_b_c=2&keep=1',
    'z[1]=1&z[b][c][d][e][f]=9&k=1',
    'q[b][c][d][e&keep=1',
    'q[b][c][d&keep=1',
    'q[b][c][&keep=1',
    '[a][b][c][d][e]=1&keep=1',
    'm[][][][]=1&n[][][]=2&keep=1',
    'p[b][c][d][e]=1&p=2&keep=1',
];
$helper = ce_helper(array_fill(0, 2 * count($fixtures), 'ok'), false);
var_dump(rinit());
set_error_handler(function ($no, $str) {
    echo "error handler: $str\n";
    throw new ErrorException($str, 0, $no);
});
foreach ($fixtures as $f) {
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $f);
    try {
        curl_exec($ch);
    } catch (\Throwable $e) {
        echo "curl_exec threw ", get_class($e), "\n";
    }
}
restore_error_handler();
var_dump(error_get_last());
$bodies = [];
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.body'])) {
        $bodies[$id] = $data['server.io.net.request.body'];
    }
}
foreach ($fixtures as $i => $f) {
    @parse_str($f, $expected);
    $id = 'curl-' . ($i + 1) . '-0';
    $got = isset($bodies[$id]) ? $bodies[$id] : null;
    $same = json_encode(ce_norm($got)) === json_encode(ce_norm($expected));
    echo $i, ': ', $same ? 'same' : 'DIFFERENT', "\n";
    if (!$same) {
        echo '  expected: ', json_encode(ce_norm($expected)), "\n";
        echo '  got:      ', json_encode(ce_norm($got)), "\n";
    }
}
?>
--EXPECT--
bool(true)
NULL
0: same
1: same
2: same
3: same
4: same
5: same
6: same
7: same
8: same
9: same
10: same
11: same
12: same
