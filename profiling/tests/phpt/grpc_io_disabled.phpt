--TEST--
[profiling] ext-grpc disables I/O profiling even with all experimental features enabled
--EXTENSIONS--
grpc
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    die('skip: test requires datadog-profiling');
ob_start();
(new ReflectionExtension('datadog-profiling'))->info();
$info = ob_get_clean();
if (strpos($info, 'built without I/O profiling support') !== false)
    die('skip: test requires I/O profiling support');
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_FEATURES_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_IO_ENABLED=no
--INI--
datadog.profiling.log_level=error
--FILE--
<?php
ob_start();
(new ReflectionExtension('datadog-profiling'))->info();
$info = ob_get_clean();
foreach (preg_split('/\R/', $info) as $line) {
    if (strpos($line, 'I/O Profiling Enabled') === 0) {
        echo $line, PHP_EOL;
    }
}
?>
--EXPECTF--
%AI/O profiling is disabled because ext-grpc can execute PHP on native threads.
%AI/O Profiling Enabled => false (incompatible with ext-grpc)
