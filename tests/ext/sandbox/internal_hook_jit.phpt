--TEST--
Test internal functions are hooked within JIT
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
<?php if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8'); ?>
--INI--
opcache.enable = 1
opcache.enable_cli = 1
opcache.jit_buffer_size = 10M
opcache.jit = 1202
zend_extension = opcache.so
--FILE--
<?php

function jitted_exec($ch) {
    curl_exec($ch);
}

DDTrace\hook_function("curl_exec", function() {
    print "Internal curl_exec() function JITed";
});

// create a new cURL resource
$ch = curl_init();

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
curl_setopt($ch, CURLOPT_URL, "$url");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

jitted_exec($ch);

?>
--EXPECT--
Internal curl_exec() function JITed
