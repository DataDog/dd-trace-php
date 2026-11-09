--TEST--
Exception replay survives exceptions thrown by opcache's compile-time function evaluation
--DESCRIPTION--
opcache's SCCP pass evaluates strrpos('', '/', -1) while optimizing f(),
calling it with a stack-allocated dummy caller frame (func = f, no CV slots).
The ValueError it throws must not make us read f()'s locals off that frame
(crashes in the wild; stack-buffer-overflow under ASAN).
--SKIPIF--
<?php if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required'); ?>
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.optimization_level=0x7FFEBFFF
opcache.file_update_protection=0
--FILE--
<?php

function f() {
    $a = $b = $c = $d = 1; // more locals read past the dummy frame: crashes without ASAN too
    return strrpos('', '/', -1);
}

echo "OK\n";

?>
--EXPECT--
OK
