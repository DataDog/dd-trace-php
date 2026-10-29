--TEST--
LineHookData does not read variables after an abandoned generator's frame is freed
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: PHP 7 delivers generator ends before freeing the frame'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function generator_locals($arg) {
    $local = 'local';
    yield $arg;
    $done = true;
}

$materialize = false;
$line = (new ReflectionFunction('generator_locals'))->getStartLine() + 2;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($hook) use (&$materialize) {
    if ($materialize) {
        $hook->vars();
    }
}, $line + 1, function ($hook) {
    echo json_encode([$hook->var('arg'), $hook->var('local'), count($hook->vars())]), "\n";
});

foreach ([false, true] as $materialize) {
    $generator = generator_locals('argument');
    $generator->current();
    unset($generator);
}

$generator = generator_locals('argument');
$generator->current();
$generator->next();
DDTrace\remove_hook($id);
?>
--EXPECT--
[null,null,0]
[null,null,0]
["argument","local",3]
