--TEST--
Downstream curl requests: urlencoded bodies are parsed natively like parse_str()
--DESCRIPTION--
The native x-www-form-urlencoded parser gives what parse_str() gives (with
the default max_input_nesting_level): name mangling, nested and appended
arrays, unterminated brackets, url-decoding, NUL bytes in names and values,
numeric keys, overwrites, names nested too deep, and the __Host- and
__Secure- prefixes (which PHP 7.4+ treats specially for cookies only).
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
$fixtures = [
    'a=1&b[]=2&b[]=3',
    'a.b=1&c d=2&  e=3&f+g=4&h%20i=5',
    'x[ y]=1&x[z]=2&x[]=3&x[  ]=4',
    'u[=1&v[a=2&w[a][b=3&t[a.b c[d=4',
    'k[a]b=1&k[c][d]=2&k[e]]=3',
    '%61%5Bb%5D=%2B+x&plus=a+b&pct=%zz%4&enc=%C3%A9',
    'n=1&n=2&&=x&novalue&=only&&&tail=',
    '0=a&1=b&-1=c&01=d&9223372036854775808=e',
    'm[1]=a&m[]=b&m[x]=c&m[]=d&m[-5]=e&m[]=f',
    'z=1&z[]=2&y[]=1&y=2&y2[a]=1&y2[a][b]=2',
    '%00a=1&b%00c=2&v=x%00y&[x]=1& =1&q[]]=1',
    'd' . str_repeat('[a]', 15) . '=ok&keep=1',
    'e[]=1&e' . str_repeat('[]', 65) . '=x&keep=1',
    'this=1&keep=1',
    '__Host-a=1&__Secure-b=2&__host-c=3&__SECURE-d=4&x=1',
    '__Host-a[]=1&__Secure-b[k]=2&__Host-.e=3&y=1',
];
$helper = ce_helper(array_fill(0, 4 * count($fixtures), 'ok'), false);
var_dump(rinit());
foreach ($fixtures as $f) {
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $f);
    curl_exec($ch);
}
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
13: same
14: same
15: same
