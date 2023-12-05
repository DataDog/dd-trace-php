--TEST--
Workaround for PHP Bug #81430
--INI--
zend_test.observer.enabled=1
zend_test.observer.observe_all=1
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) {
    echo "skip requires 8 due to attributes";
}
--FILE--
<?php
// https://github.com/tideways/php-xhprof-extension/pull/109/files

namespace X; // avoid cuf() being optimized away

ini_set("memory_limit", "20M");

#[\Attribute]
class A {
        public function __construct() {}
}

#[A]
function B() {}

$r = new \ReflectionFunction("X\\B");
var_dump(call_user_func([$r->getAttributes(A::class)[0], 'newInstance']));

array_map("str_repeat", ["\xFF"], [100000000]); // cause a bailout

--EXPECTF--
int(8)
object(A)#9 (1) {
  ["foo":"A":private]=>
  string(3) "bar"
}
object(A)#8 (1) {
  ["foo":"A":private]=>
  string(3) "bar"
}
