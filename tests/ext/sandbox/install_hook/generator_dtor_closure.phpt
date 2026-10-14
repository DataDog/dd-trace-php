--TEST--
An abandoned generator keeps its closure alive through the end hook
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: PHP 8 generator lifecycle'); ?>
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
--FILE--
<?php
class CapturedValue {}

function check_generator_closure($mode) {
    echo "$mode\n";
    $captured = new CapturedValue();
    $capturedRef = WeakReference::create($captured);
    if ($mode === 'finally') {
        $closure = static function () use ($captured) {
            try {
                yield 1;
            } finally {
                echo "finally\n";
            }
        };
    } else {
        $closure = static function () use ($captured) {
            yield 1;
        };
    }
    $closureRef = WeakReference::create($closure);
    DDTrace\install_hook($closure, null, static function () use ($closureRef, $capturedRef) {
        echo 'end: ';
        var_dump($closureRef->get() instanceof Closure, $capturedRef->get() instanceof CapturedValue);
    }, DDTrace\HOOK_INSTANCE);

    $generator = $closure();
    unset($closure, $captured);
    if ($mode !== 'unstarted') {
        $generator->current();
    }
    if ($mode === 'return') {
        $generator->next();
    }
    unset($generator);
    echo 'released: ';
    var_dump($closureRef->get() === null, $capturedRef->get() === null);
}

foreach (['unstarted', 'suspended', 'finally', 'return'] as $mode) {
    check_generator_closure($mode);
}
?>
--EXPECT--
unstarted
end: bool(true)
bool(true)
released: bool(true)
bool(true)
suspended
end: bool(true)
bool(true)
released: bool(true)
bool(true)
finally
finally
end: bool(true)
bool(true)
released: bool(true)
bool(true)
return
end: bool(true)
bool(true)
released: bool(true)
bool(true)
