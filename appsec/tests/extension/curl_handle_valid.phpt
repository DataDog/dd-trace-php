--TEST--
The CURL * of live curl handles is found and validated
--DESCRIPTION--
On the CI images ext/curl is bound to a dynamic libcurl (>= 7.75.0, so the
magic number is checked), and the CURL * of handles from curl_init() and
curl_copy_handle() passes validation. Non-curl values are recognised as such,
without side effects.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--FILE--
<?php
use function datadog\appsec\testing\curl_handle_info;

function show($label, $v) {
    $i = curl_handle_info($v);
    echo "$label: {$i['status']} validated=", var_export($i['validated'], true),
        " reason='{$i['reason']}'\n";
}

$ch = curl_init('http://localhost/');
show('curl_init', $ch);
$ch2 = curl_copy_handle($ch);
show('curl_copy_handle', $ch2);
$ref = &$ch;
show('reference', $ref);

show('null', null);
show('int', 42);
show('string', 'curl');
show('array', [$ch]);
show('object', new stdClass);
$mh = curl_multi_init();
show('curl_multi', $mh);
$sh = curl_share_init();
show('curl_share', $sh);
$f = fopen('php://memory', 'r');
show('stream', $f);
?>
--EXPECT--
curl_init: ok validated=true reason=''
curl_copy_handle: ok validated=true reason=''
reference: ok validated=true reason=''
null: not_curl validated=false reason=''
int: not_curl validated=false reason=''
string: not_curl validated=false reason=''
array: not_curl validated=false reason=''
object: not_curl validated=false reason=''
curl_multi: not_curl validated=false reason=''
curl_share: not_curl validated=false reason=''
stream: not_curl validated=false reason=''
