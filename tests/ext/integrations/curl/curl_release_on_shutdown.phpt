--TEST--
Curl multi objects release order does not crash on shutdown
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
--FILE--
<?php

$ch = curl_init();
$mh = curl_multi_init();
curl_multi_add_handle($mh, $ch);

class A {
    public static $a; // static var to ensure delaying destruction until all object destructors are collectively called
    public $ch;
    public $mh;

    function __destruct() {
        curl_multi_remove_handle($this->mh, $this->ch);
        echo "DONE\n";
    }
}

A::$a = new A;
A::$a->ch = $ch;
A::$a->mh = $mh;

?>
--EXPECT--
DONE
