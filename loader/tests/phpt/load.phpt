--TEST--
The loader is able to load ddtrace
--SKIPIF--
<?php if (PHP_MAJOR_VERSION <= 5) die("skip: The loader extension is not compatible with PHP 5"); ?>
--FILE--
<?php

$filter = function ($name) {
    return in_array($name, ['ddtrace', 'ddtrace_injected', 'dd_library_loader']);
};

$exts = array_filter(get_loaded_extensions(false), $filter);
var_dump(array_values($exts));

$exts = array_filter(get_loaded_extensions(true), $filter);
var_dump(array_values($exts));

?>
--EXPECT--
array(1) {
  [0]=>
  string(7) "ddtrace"
}
array(2) {
  [0]=>
  string(17) "dd_library_loader"
  [1]=>
  string(7) "ddtrace"
}
