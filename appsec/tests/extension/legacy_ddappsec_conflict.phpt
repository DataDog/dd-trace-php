--TEST--
A legacy ddappsec.so cannot be loaded alongside ddtrace, in any order
--SKIPIF--
<?php
$legacy = getenv('DD_APPSEC_TEST_LEGACY_EXTENSION');
if (!$legacy) {
    die('skip DD_APPSEC_TEST_LEGACY_EXTENSION does not point to a ddappsec.so');
}
$cmd = [getenv('TEST_PHP_EXECUTABLE'), '-n', '-d', "extension=$legacy", '-r', ''];
exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output);
if (strpos(implode("\n", $output), 'Unable to load') !== false) {
    die('skip the legacy ddappsec.so does not match this PHP build');
}
?>
--FILE--
<?php
$ext_dir = ini_get('extension_dir');
$ddtrace = "$ext_dir/ddtrace.so";
$legacy = getenv('DD_APPSEC_TEST_LEGACY_EXTENSION');

$base = [getenv('TEST_PHP_EXECUTABLE'), '-n'];
foreach (['json', 'xml'] as $ext) {
    if (file_exists("$ext_dir/$ext.so")) {
        array_push($base, '-d', "extension=$ext_dir/$ext.so");
    }
}

foreach ([[$ddtrace, $legacy], [$legacy, $ddtrace]] as $order) {
    $cmd = array_merge($base, ['-d', "extension=$order[0]", '-d', "extension=$order[1]", '-r', 'echo "survived\n";']);
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $status);
    echo implode("\n", $output), "\n";
    echo $status === 0 ? "exited cleanly\n" : "failed\n";
    $output = [];
}
?>
--EXPECTF--
%AFatal error: ddappsec cannot be loaded alongside ddtrace, which includes AppSec support; remove extension=ddappsec.so from the PHP configuration in Unknown on line 0
failed
%AFatal error: ddappsec cannot be loaded alongside ddtrace, which includes AppSec support; remove extension=ddappsec.so from the PHP configuration in Unknown on line 0
failed
