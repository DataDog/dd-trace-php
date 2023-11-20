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

$maps = file_get_contents('/proc/self/maps');
if (preg_match('@(?<=\\s)\\S*/ddappsec\\.so$@m', $maps, $m) != 1) {
    die('cannot find loaded ddappsec.so');
}

$ddappsec_so = $m[0];
exec('readelf -d ' . escapeshellarg($ddappsec_so) . ' | grep STATIC_TLS', $garbage, $r);

if ($r == 0) {
    echo "Found STATIC_TLS\n";
} else {
    echo "STATIC_TLS not found (OK)\n";
}
--EXPECT--
STATIC_TLS not found (OK)
