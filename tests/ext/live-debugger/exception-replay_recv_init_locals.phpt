--TEST--
Exception replay locals of a frame whose parameter default throws
--DESCRIPTION--
RECV_INIT evaluates constant expression defaults in place, so while evaluating
the default of $b, its CV holds the unevaluated constant AST, which must not be
exposed. The defaults already assigned to earlier parameters are regular values.
Before PHP 8.5, without global IP register, EX(opline) stays at the first of
consecutive RECV_INIT ops while they are being processed, so it does not tell
which parameter is being received. With type declarations, the received
parameters are not skipped, and EX(opline) points to the RECV_INIT of $a, which
has been passed, and must not be confused with a skipped named parameter.
A missing class constant is used, as undefined global constants only throw
since PHP 8.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip: parameters are only captured as locals with zend.exception_ignore_args, which requires PHP 7.4'); ?>
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--INI--
zend.exception_ignore_args=1
--FILE--
<?php

const FOO = "foo";

class C {}

function f($a = 1, $b = C::UNDEFINED, $c = 3) {
    $l = 1;
}

function g(int $a = 0, $x = FOO . "bar", $b = C::UNDEFINED, $c = 3) {
    $l = 1;
}

function check($fn) {
    try {
        $fn();
    } catch (Error $e) {
        echo get_class($e), "\n"; // the message differs between PHP versions
        $locals = new ReflectionProperty(Error::class, 'locals');
        @$locals->setAccessible(true);
        var_dump($locals->getValue($e));
    }
}

check(function () { f(); });
check(function () { g(5); });

?>
--EXPECT--
Error
array(1) {
  ["a"]=>
  int(1)
}
Error
array(2) {
  ["a"]=>
  int(5)
  ["x"]=>
  string(6) "foobar"
}
