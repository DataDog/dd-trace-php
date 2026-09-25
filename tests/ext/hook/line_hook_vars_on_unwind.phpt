--TEST--
An end callback reached by an exception unwind can still read the frame's variables
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function boom($tag) {
    $local = $tag . '-local';       // 4  begin
    $more = 'more';
    throw new RuntimeException('x');
    $never = 1;                     // 7  end (never reached)
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = 'B' . $h->line . ':' . var_export($h->var('local'), true);
};
$e = function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = 'E' . $h->line . ':' . var_export($h->var('local'), true) . ':' . count($h->vars());
};
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 7, $e);

try {
    boom('t');
} catch (RuntimeException $ex) {
    echo "caught\n";
}
echo implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
caught
B4:NULL,E7:'t-local':3
Done.
