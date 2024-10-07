--TEST--
Extension is not compiled with STATIC_TLS
--SKIPIF--
<?php
exec('command -v readelf', $garbage, $r);
if ($r != 0) { die('skip: no readelf command'); }
if (!file_exists('/proc/self/maps')) { die('skip: no /proc/self/maps'); }
?>
--FILE--
<?php

// If this test is failing, you're missing an inclusion of ddtrace.h or
// tsrmls_cache.h (the latter in zend_abstract_interface.h) in some compilation
// unit that uses TSRMLS_CACHE (aka _tsrm_ls_cache), possibly through macros like
// EG().
//
// To find out which compilation unit(s) this is happening in, you can:
//
// 1. Find relocations of type R_X86_64_TPOFF64 (on amd64):
//    $ readelf -r **/ddtrace.so | grep R_X86_64_TPOFF64
//      000000523bf8  000000000012 R_X86_64_TPOFF64                     280
// 2. Disassemble the extension:
//    $ objdump -d **/ddtrace.so | vim -
// 3. Look for references to the offset 523bf8
// 4. See which function the offset shows up in
// 5. Determine in which compilation unit(s) this function is defined

$maps = file_get_contents('/proc/self/maps');
if (preg_match('@(?<=\\s)\\S*/ddtrace\\.so$@m', $maps, $m) != 1) {
    die('cannot find loaded ddtrace.so');
}

$ddtrace_so = $m[0];
exec('readelf -d ' . escapeshellarg($ddtrace_so) . ' | grep STATIC_TLS', $garbage, $r);

if ($r == 0) {
    echo "Found STATIC_TLS\n";
} else {
    echo "STATIC_TLS not found (OK)\n";
}
--EXPECT--
STATIC_TLS not found (OK)
