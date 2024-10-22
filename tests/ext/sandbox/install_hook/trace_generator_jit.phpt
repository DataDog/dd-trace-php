--TEST--
generator hooking works with JIT
--SKIPIF--
<?php if (!file_exists(ini_get("extension_dir") . "/opcache.so")) die('skip: opcache.so does not exist in extension_dir'); ?>
<?php if (PHP_VERSION_ID < 80100) die('skip: JIT is only on PHP 8, and not stable enough on PHP 8.0'); ?>
--INI--
opcache.enable=1
opcache.enable_cli = 1
opcache.jit_buffer_size=128M
opcache.jit=1255
zend_extension=opcache.so
--FILE--
<?php

function gen() {
    yield 1;
    yield 2;
    return 3;
};

$hooks[] = \DDTrace\hook_function("gen", [
    "posthook" => function($args, $retval) {
        var_dump($retval);
    }
]);

for ($i = 0; $i < 2; ++$i) {
    foreach (gen() as $val) {
        var_dump($val);
    }
}

?>
--EXPECT--
int(1)
int(1)
int(2)
int(2)
int(1)
int(1)
int(2)
int(2)
