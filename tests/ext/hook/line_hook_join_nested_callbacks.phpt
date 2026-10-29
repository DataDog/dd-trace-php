--TEST--
End-only hooks join recursive calls across nested callback sandboxes and bailouts
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip WeakReference requires PHP 7.4'); ?>
--INI--
error_reporting=24575
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($depth) {
    if ($depth) target($depth - 1);
    echo "body $depth\n";
    return $depth;
}

function installer() {
    echo "installer body\n";
}

foreach ([false, true] as $bailout) {
    echo $bailout ? "bailout\n" : "normal\n";
    $fn = null;
    $weak = null;
    $nested = DDTrace\install_hook('installer', function () use (&$fn, &$weak, $bailout) {
        $capture = new stdClass();
        $weak = WeakReference::create($capture);
        $fn = DDTrace\install_hook('target', null, function ($hook) use ($capture) {
            echo "end ", $hook->returned, "\n";
        });
        unset($capture);
        if ($bailout) trigger_error('nested callback bailout', E_USER_ERROR);
    });
    $line = (new ReflectionFunction('target'))->getStartLine() + 2;
    $probe = DDTrace\install_line_hook(__FILE__, $line, function () use (&$fn, $bailout) {
        if ($fn !== null) return;
        installer();
        echo "line callback\n";
        if ($bailout) trigger_error('line callback bailout', E_USER_ERROR);
    });

    target(2);
    DDTrace\remove_hook($probe);
    DDTrace\remove_hook($nested);
    DDTrace\remove_hook($fn);
    gc_collect_cycles();
    echo "capture released: ", var_export($weak->get() === null, true), "\n";
}
?>
--EXPECT--
normal
installer body
line callback
body 0
end 0
body 1
end 1
body 2
end 2
capture released: true
bailout
installer body
line callback
body 0
end 0
body 1
end 1
body 2
end 2
capture released: true
