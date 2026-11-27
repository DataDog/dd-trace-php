--TEST--
ext/curl's libcurl is detected as dynamically linked, resolved and calibrated
--DESCRIPTION--
The CI images link ext/curl (built into php) against the system libcurl, so
the native downstream-request analysis must be available, with the libcurl
functions resolved from the same libcurl instance ext/curl uses. In the
debug-zts and debug-zts-asan legs this must pass, never skip (G10.5); the
other curl tests skip when the linkage is not enabled
(inc/curl_skipif.php), so this is the test that fails for any cause of "off".

Identity (G10.13): the libcurl ext/curl is bound to is the one the leg selects.
A leg that selects a libcurl with LD_LIBRARY_PATH (the images of
dockerfiles/ci/libcurl, G10.3) names its version in
DD_APPSEC_TEST_EXPECTED_LIBCURL; the in-process linkage (the libcurl the
extension resolved its functions from) and curl_version() (the libcurl ext/curl
calls) must both report it, and the resolved file must be in that prefix. Not
ldd or readelf: they would not see a libcurl the process did not load.
--SKIPIF--
<?php
// the variant: this test must fail, not skip, when the layer is disabled
include __DIR__ . '/inc/curl_skipif_testing.php';
// no extension_loaded('curl') skip either: without ext/curl the leg fails
?>
--FILE--
<?php
$info = \datadog\appsec\testing\curl_linkage_info();
var_dump($info['enabled']);
var_dump(preg_match("/^ext\/curl in '[^']+' is bound to dynamic libcurl libcurl[^ ]*\.so\.\d+$/", $info['reason']));
var_dump(preg_match('/^\/.*\/libcurl[^\/]*\.so(\.\d+)+$/', $info['libcurl_path']));
var_dump(file_exists($info['libcurl_path']));

// the version reported by the libcurl ext/curl calls
$cv = curl_version();
var_dump($info['libcurl_version'] === $cv['version']);
var_dump($info['libcurl_version_num'] === $cv['version_number']);
var_dump($info['libcurl_version_num'] >= 0x070a00);

// the leg's expected version, when it selects one (G10.3, G10.13)
$expected = getenv('DD_APPSEC_TEST_EXPECTED_LIBCURL');
if ($expected !== false && $expected !== '') {
    var_dump($info['libcurl_version'] === $expected);
    var_dump($cv['version'] === $expected);
    var_dump(strpos($info['libcurl_path'], "/$expected/lib/") !== false);
    // the pinned leg must really be below 7.80 (G10.4)
    var_dump(strpos($expected, '7.79') !== 0
        || $info['libcurl_version_num'] < 0x075000);
} else {
    var_dump(true, true, true, true);
}
?>
--EXPECTF--
bool(true)
int(1)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
