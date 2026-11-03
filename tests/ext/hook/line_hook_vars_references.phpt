--TEST--
LineHookData variable reads use the hooked scope and return values without reference aliases
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function read_locals(&$arg) {
    $local = 'local';
    $alias =& $local;
    $unset = 'gone';
    unset($unset);
    extract(['dynamic' => 'dynamic']);
    echo "$arg/$local\n";
}

$line = (new ReflectionFunction('read_locals'))->getStartLine() + 6;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($hook) {
    $arg = $local = $dynamic = 'callback';
    $vars = $hook->vars();
    ksort($vars);
    echo json_encode($vars), "\n";
    foreach (['arg', 'alias', 'dynamic'] as $name) {
        $vars[$name] = 'changed';
    }
    echo json_encode([
        $hook->var('arg'), $hook->var('local'), $hook->var('dynamic'),
        $hook->var('unset'), $hook->var('missing'),
    ]), "\n";
});

$argument = 'argument';
read_locals($argument);
echo $argument, "\n";
DDTrace\remove_hook($id);
?>
--EXPECT--
{"alias":"local","arg":"argument","dynamic":"dynamic","local":"local"}
["argument","local","dynamic",null,null]
argument/local
argument
