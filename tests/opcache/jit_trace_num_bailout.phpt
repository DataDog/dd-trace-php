--TEST--
[Sandbox regression] Bailout in a hot hook closure restores the active JIT trace
--DESCRIPTION--
Tracing JIT is enabled with very low hot thresholds so both the caller and the
hook closure run inside JIT traces. The hook closure deliberately raises a fatal
error that ddtrace catches through its sandbox, then the caller continues and
takes JIT side exits. Without restoring EG(jit_trace_num) during the sandbox
bailout path, the resumed caller can exit through the wrong trace metadata and
crash in zend_jit_trace_exit().
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only available on PHP 8+');
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=128M
opcache.jit=1255
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
opcache.jit_hot_return=1
opcache.jit_hot_side_exit=1
--FILE--
<?php

$armed = false;
$bailoutEnabled = false;
$bailed = false;

DDTrace\hook_function('observed_call', function () use (&$armed, &$bailoutEnabled, &$bailed) {
    static $calls = 0;

    $calls++;
    $sum = 0;
    for ($i = 0; $i < 48; $i++) {
        $value = $i + $calls;
        $sum += ($value & 1) ? $value : -$value;
    }

    if ($armed && $calls > 2048) {
        $sum += $calls & 7;

        if ($bailoutEnabled && !$bailed) {
            $bailed = true;
            trigger_error('force sandbox bailout after entering a JIT trace', E_USER_ERROR);
        }
    }

    return $sum;
});

function observed_call(int $value): int
{
    return $value + 1;
}

function fold_value($value): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value)) {
        return strlen($value);
    }

    return (int) $value;
}

function hot_caller(int $round): int
{
    global $bailed;

    $sum = 0;
    $v0 = $round;
    $v1 = $round + 1;
    $v2 = $round + 2;
    $v3 = $round + 3;

    for ($i = 0; $i < 96; $i++) {
        $sum += observed_call($i);

        if ($bailed && $i === 17) {
            $v0 = 'trace-exit-a';
        }
        if ($bailed && $i === 29) {
            $v1 = 3.14;
        }
        if ($bailed && $i === 41) {
            $v2 = [];
        }
        if ($bailed && $i === 53) {
            $v3 = null;
        }

        $sum += fold_value($v0);
        $sum += fold_value($v1);
        $sum += fold_value($v2);
        $sum += fold_value($v3);
    }

    return $sum;
}

for ($i = 0; $i < 64; $i++) {
    hot_caller($i);
}

$armed = true;

for ($i = 0; $i < 64; $i++) {
    hot_caller($i);
}

$bailoutEnabled = true;

for ($i = 0; $i < 64; $i++) {
    hot_caller($i);
}

echo "ok\n";
?>
--EXPECT--
ok
