--TEST--
Exception replay does not capture uninitialized locals while evaluating a skipped named argument's default
--DESCRIPTION--
When a named-argument call skips a parameter whose default value throws,
zend_handle_undef_args() evaluates that default with the callee frame as the
current frame, before the callee's non-argument CVs have been initialized.
Those slots still hold whatever a previous frame left on the VM stack, here
the integers assigned by pollute(). They must not be captured as locals.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: named arguments require PHP 8'); ?>
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--FILE--
<?php

function pollute() {
    $p0 = 100; $p1 = 101; $p2 = 102; $p3 = 103; $p4 = 104;
    return 0;
}

function callee($a = UNDEFINED_CONSTANT_FOR_TEST, $b = 2) {
    $l1 = 1; $l2 = 2; $l3 = 3;
    return $b;
}

pollute();
try {
    callee(b: 5);
} catch (Error $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
    $locals = new ReflectionProperty(Error::class, 'locals');
    @$locals->setAccessible(true);
    var_dump($locals->getValue($e));
}

?>
--EXPECT--
Error: Undefined constant "UNDEFINED_CONSTANT_FOR_TEST"
array(0) {
}
