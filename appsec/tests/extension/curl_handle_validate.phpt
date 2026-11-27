--TEST--
Validation of CurlHandle handler offsets and CURL * pointers
--DESCRIPTION--
Exercises the validators directly (they change no state): alignment, the
single process_vm_readv(2) probe (which also refuses non-canonical and
kernel-half addresses, with no range check of our own) and the magic number.
--SKIPIF--
<?php include __DIR__ . '/inc/curl_skipif.php';
--FILE--
<?php
use function datadog\appsec\testing\{curl_handle_validate,curl_string_buffer_address};

function v($label, ...$args) {
    $r = curl_handle_validate(...$args);
    $r = $r === null ? 'valid' : preg_replace('/pointer 0x[0-9a-f]+/', 'pointer ADDR', $r);
    echo "$label: $r\n";
}

// keep the strings alive while their addresses are used
$garbage = str_repeat('A', 16);
$magic = "\xad\xdb\xde\xc0" . str_repeat("\0", 12);
$garbage_addr = curl_string_buffer_address($garbage);
$magic_addr = curl_string_buffer_address($magic);

echo "-- handler offsets\n";
foreach ([-8, 0, 4, 12, 4095, 4096, 1 << 20] as $off) {
    v("offset $off", $magic_addr, $off);
}
foreach ([8, 16, 400, 4088] as $off) {
    v("offset $off", $magic_addr, $off);
}

echo "-- pointers\n";
v('NULL', 0);
v('unaligned', 0x10004);
v('4-aligned', $magic_addr + 4);
v('below 4096', 0x100);
v('2^48', 0x1000000000000);
v('kernel half', -0x800000000000);
v('all ones & ~7', -8);

echo "-- probe\n";
v('unmapped', 0x8000);
v('unmapped, libcurl 7.74.0', 0x8000, 8, 0x074a00);
v('garbage, libcurl 7.75.0', $garbage_addr, 8, 0x074b00);
v('garbage, libcurl 7.74.0', $garbage_addr, 8, 0x074a00);
v('magic', $magic_addr);
v('magic, libcurl 7.74.0', $magic_addr, 8, 0x074a00);
?>
--EXPECT--
-- handler offsets
offset -8: the CurlHandle object handlers' offset is smaller than a pointer
offset 0: the CurlHandle object handlers' offset is smaller than a pointer
offset 4: the CurlHandle object handlers' offset is smaller than a pointer
offset 12: the CurlHandle object handlers' offset is not a multiple of the pointer size
offset 4095: the CurlHandle object handlers' offset is not a multiple of the pointer size
offset 4096: the CurlHandle object handlers' offset is implausibly large
offset 1048576: the CurlHandle object handlers' offset is implausibly large
offset 8: valid
offset 16: valid
offset 400: valid
offset 4088: valid
-- pointers
NULL: NULL CURL handle pointer
unaligned: CURL handle pointer ADDR is not 8-byte aligned
4-aligned: CURL handle pointer ADDR is not 8-byte aligned
below 4096: CURL handle pointer ADDR points to unreadable memory
2^48: CURL handle pointer ADDR points to unreadable memory
kernel half: CURL handle pointer ADDR points to unreadable memory
all ones & ~7: CURL handle pointer ADDR points to unreadable memory
-- probe
unmapped: CURL handle pointer ADDR points to unreadable memory
unmapped, libcurl 7.74.0: CURL handle pointer ADDR points to unreadable memory
garbage, libcurl 7.75.0: CURL handle pointer ADDR does not point to a libcurl easy handle (magic 0x41414141, expected 0xc0dedbad)
garbage, libcurl 7.74.0: valid
magic: valid
magic, libcurl 7.74.0: valid
