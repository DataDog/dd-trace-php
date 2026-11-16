--TEST--
Windows line hook paths accept either separator and enforce range conflicts across spellings
--SKIPIF--
<?php if (DIRECTORY_SEPARATOR !== '\\') die('skip Windows paths'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function path_target() {
    $a = 1; // 4
    $b = 2;
    $c = 3;
    $d = 4;
    return $a + $b + $c + $d;
}

$forward = str_replace('\\', '/', __FILE__);
$paths = [
    __FILE__,
    $forward,
    __DIR__ . '/' . basename(__FILE__),
    'hook/' . basename(__FILE__),
    'hook\\' . basename(__FILE__),
];
foreach ($paths as $path) {
    $hits = 0;
    $id = DDTrace\install_line_hook($path, 4, function () use (&$hits) { ++$hits; });
    path_target();
    DDTrace\remove_hook($id);
    var_dump($hits);
}

$hits = 0;
$id = DDTrace\install_line_hook('ook/' . basename(__FILE__), 4, function () use (&$hits) { ++$hits; });
path_target();
DDTrace\remove_hook($id);
var_dump($hits);

foreach ([[__FILE__, $forward], [$forward, __FILE__]] as $pair) {
    list($first, $second) = $pair;
    $id = DDTrace\install_line_hook($first, 4, function () {}, 6, function () {});
    try {
        DDTrace\install_line_hook($second, 5, function () {}, 7, function () {});
        echo "accepted (wrong)\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
    DDTrace\remove_hook($id);
}
?>
--EXPECT--
int(1)
int(1)
int(1)
int(1)
int(1)
int(0)
Line hook range partially overlaps an existing range in the same file
Line hook range partially overlaps an existing range in the same file
