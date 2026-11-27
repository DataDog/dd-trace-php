--TEST--
A CURL * pointing to unreadable memory skips only that transfer
--DESCRIPTION--
A validation failure affects only the transfer it happens in: handles that
validated before, and new ones, are still validated and observed, and every
failure has its own reason. A NULL CURL * is skipped without being a
validation failure.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--FILE--
<?php
use function datadog\appsec\testing\{curl_handle_info,curl_handle_inject};

function show($label, $i) {
    echo "$label: {$i['status']} validated=", var_export($i['validated'], true),
        " reason='{$i['reason']}'\n";
}

$ch = curl_init();
show('before', curl_handle_info($ch));
show('NULL cp', curl_handle_inject(0));
show('unmapped', curl_handle_inject(0x8000));
show('same handle', curl_handle_info($ch));
show('new handle', curl_handle_info(curl_init()));
show('not a handle', curl_handle_info(42));
show('another failure', curl_handle_inject(0x10008));
show('kernel half', curl_handle_inject(-0x800000000000));
show('NULL cp', curl_handle_inject(0));
show('last handle', curl_handle_info($ch));
?>
--EXPECT--
before: ok validated=true reason=''
NULL cp: null_cp validated=false reason=''
unmapped: invalid validated=false reason='CURL handle pointer 0x8000 points to unreadable memory'
same handle: ok validated=true reason=''
new handle: ok validated=true reason=''
not a handle: not_curl validated=false reason=''
another failure: invalid validated=false reason='CURL handle pointer 0x10008 points to unreadable memory'
kernel half: invalid validated=false reason='CURL handle pointer 0xffff800000000000 points to unreadable memory'
NULL cp: null_cp validated=false reason=''
last handle: ok validated=true reason=''
